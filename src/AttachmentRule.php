<?php

namespace GlpiPlugin\Itsmmigrator;

/**
 * Would GLPI take this file as a document? GLPI refuses a file larger than its "document max size" (2 MB by default)
 * and a file whose extension is not an uploadable document type. Found at the first real run of phase 1: zip files of
 * 2 to 12 MB and .json / .cs / .cshtml files were refused, and each refusal failed the whole ticket. Pure.
 */
final class AttachmentRule
{
    /**
     * @param list<string> $uploadableExts lower-case extensions of the uploadable document types
     * @return string|null the reason, or null when GLPI would take it
     */
    public static function refusal(string $name, int $bytes, int $maxMb, array $uploadableExts): ?string
    {
        if ($maxMb > 0 && $bytes > $maxMb * 1048576) {
            return sprintf('larger than the GLPI limit of %d MB (%s)', $maxMb, self::size($bytes));
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $uploadableExts, true)) {
            return $ext === '' ? 'no file extension, so no GLPI document type' : sprintf('the file type .%s is not an allowed GLPI document type', $ext);
        }
        return null;
    }

    /**
     * The name a file is stored under in GLPI. A file that PHP would run (.php, .phtml, .phar, .php5...) keeps its
     * information but loses its power: the extension gets an underscore after "ph" (.php -> .ph_p, .phtml -> .ph_tml,
     * .php5 -> .ph_p5, .phar -> .ph_ar), so it is still plain to see that it is a PHP file and no server will run it.
     * Any other name is returned as it is. Pure.
     */
    public static function safeName(string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext !== '' && preg_match('/^ph(p\d?|tml|ar|ps|t)$/i', $ext) === 1) {
            return substr($name, 0, -strlen($ext)) . 'ph_' . strtolower(substr($ext, 2));
        }
        return $name;
    }

    /** True when safeName() changed the name. Pure. */
    public static function wasRenamed(string $name): bool
    {
        return self::safeName($name) !== $name;
    }

    public static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', max(1, (int) round($bytes / 1024)));
    }
}
