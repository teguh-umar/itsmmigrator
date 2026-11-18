<?php

namespace GlpiPlugin\Itsmmigrator\Adapter\Freshservice;

use GlpiPlugin\Itsmmigrator\Adapter\ClientException;

/**
 * Freshservice API v2, GET only (spec §7). Basic auth "{key}:X". Written from the v2 documentation:
 * paths, parameters and field names are NOT yet confirmed against a real account.
 *
 * - 429 -> wait Retry-After (60 s when absent), at most 5 retries; 5xx -> exponential backoff.
 * - A minimum gap between requests keeps the rate low (the source is a live production service).
 * - The Authorization header goes only to the Freshservice host. Attachment links are pre-signed
 *   S3 URLs and are fetched WITHOUT it.
 * - TLS is always verified.
 */
final class FreshserviceClient
{
    public const PER_PAGE    = 100;
    public const MAX_RETRIES = 5;

    /** @var callable(string,array<int,string>,?string):array{status:int,headers:array<string,string>,body:string} */
    private $transport;
    /** @var callable(float):void */
    private $sleeper;
    private float $lastRequestAt = 0.0;

    /**
     * @param callable|null $transport fn(string $url, list<string> $headerLines, ?string $sinkPath): array{status,headers,body}
     *                                 (tests inject one; production uses curl)
     * @param callable|null $sleeper   fn(float $seconds): void
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?callable $transport = null,
        ?callable $sleeper = null,
        private readonly float $minGapSeconds = 0.4,
        private readonly ?string $caBundle = null,
    ) {
        if (!preg_match('#^https://[^\s/]+$#i', rtrim($this->baseUrl, '/'))) {
            throw new ClientException('The Freshservice base URL must be https://<domain>', 0, 'config');
        }
        $this->transport = $transport ?? [$this, 'curlTransport'];
        $this->sleeper   = $sleeper ?? static function (float $s): void {
            usleep((int) ($s * 1_000_000));
        };
    }

    /**
     * One GET, decoded. Retries are handled here.
     *
     * @param array<string,scalar> $query
     * @return array{json: array<mixed>, link_next: bool}
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v2' . $endpoint;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $headers = [
            'Authorization: Basic ' . base64_encode($this->apiKey . ':X'),
            'Accept: application/json',
        ];

        $res = $this->request($url, $headers, null);
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new ClientException("Freshservice answered a non-JSON body for $endpoint", $res['status'], 'format');
        }
        $link = '';
        foreach ($res['headers'] as $k => $v) {
            if (strtolower((string) $k) === 'link') {
                $link = (string) $v;
            }
        }
        return ['json' => $json, 'link_next' => str_contains($link, 'rel="next"')];
    }

    /**
     * Download a pre-signed attachment URL to $destPath, WITHOUT the Authorization header.
     *
     * @throws ClientException
     */
    public function download(string $url, string $destPath): void
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new ClientException('Attachment link is not https', 0, 'config');
        }
        $res = $this->request($url, ['Accept: */*'], $destPath);
        if ($res['status'] !== 200) {
            throw new ClientException('Attachment download failed (HTTP ' . $res['status'] . ')', $res['status']);
        }
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function request(string $url, array $headers, ?string $sink): array
    {
        $attempt = 0;
        while (true) {
            $this->throttle();
            $res = ($this->transport)($url, $headers, $sink);
            $this->lastRequestAt = microtime(true);
            $st = (int) $res['status'];

            if ($st >= 200 && $st < 300) {
                return $res;
            }
            if ($st === 429 || $st >= 500) {
                if ($attempt >= self::MAX_RETRIES) {
                    throw new ClientException("Giving up after $attempt retries (HTTP $st)", $st, $st === 429 ? 'rate_limit' : 'server');
                }
                $wait = $st === 429 ? self::retryAfter($res['headers']) : (float) (2 ** $attempt);
                ($this->sleeper)($wait);
                $attempt++;
                continue;
            }
            throw new ClientException(self::describe($st), $st, $st === 401 || $st === 403 ? 'auth' : ($st === 404 ? 'not_found' : 'http'));
        }
    }

    /** Seconds to wait after a 429 (header Retry-After, else 60). Pure. @param array<string,string> $headers */
    public static function retryAfter(array $headers): float
    {
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === 'retry-after' && is_numeric($v) && (float) $v > 0) {
                return min((float) $v, 300.0);
            }
        }
        return 60.0;
    }

    private static function describe(int $status): string
    {
        return match (true) {
            $status === 401 => 'Freshservice refused the API key (HTTP 401)',
            $status === 403 => 'The API key has no access to this resource (HTTP 403)',
            $status === 404 => 'Freshservice has no such resource (HTTP 404)',
            default         => "Freshservice answered HTTP $status",
        };
    }

    private function throttle(): void
    {
        $gap = $this->minGapSeconds - (microtime(true) - $this->lastRequestAt);
        if ($this->lastRequestAt > 0.0 && $gap > 0) {
            ($this->sleeper)($gap);
        }
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function curlTransport(string $url, array $headers, ?string $sink): array
    {
        $respHeaders = [];
        $fh = $sink !== null ? fopen($sink, 'wb') : null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => false, // a redirect would carry the key to another host
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[trim($k)] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($this->caBundle !== null && $this->caBundle !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
        }
        if ($fh !== null) {
            curl_setopt($ch, CURLOPT_FILE, $fh);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        if ($body === false) {
            // curl's message can echo the URL but never the Authorization header.
            throw new ClientException('Network error talking to Freshservice: ' . $err, 0, 'network');
        }
        return ['status' => $code, 'headers' => $respHeaders, 'body' => $sink !== null ? '' : (string) $body];
    }
}
