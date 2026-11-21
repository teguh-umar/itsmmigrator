<?php

namespace GlpiPlugin\Itsmmigrator;

use Html;
use Session;

/**
 * The Run page (spec §11): start a run (dry run is the default), watch it, see the lock, release a
 * stale one, read the history. The run itself is driven by ajax/run.php, one batch per request.
 */
final class RunView
{
    private const AJAX = '/plugins/itsmmigrator/ajax/run.php';

    public static function render(): void
    {
        $canRun = Session::haveRight(RightDefinition::RUN, UPDATE);
        self::lockPanel($canRun);
        if ($canRun) {
            self::startPanel();
        }
        self::history();
    }

    private static function lockPanel(bool $canRun): void
    {
        $cur = Lock::current();
        echo '<div class="card mb-3"><div class="card-body d-flex align-items-center justify-content-between">';
        if ($cur === null) {
            echo '<span><i class="ti ti-lock-open me-2"></i>' . htmlescape(__('No run is active.', 'itsmmigrator')) . '</span>';
        } else {
            $beat  = strtotime((string) ($cur['heartbeat_at'] ?? $cur['started_at'])) ?: 0;
            $stale = (time() - $beat) > Lock::STALE_AFTER;
            echo '<span><i class="ti ti-lock me-2"></i>' . htmlescape(sprintf(
                __('Run %1$d (%2$s) started at %3$s is active%4$s.', 'itsmmigrator'),
                (int) $cur['id'],
                (string) $cur['runner'],
                (string) $cur['started_at'],
                $stale ? ' - ' . __('its heartbeat is older than 15 minutes, it is probably dead', 'itsmmigrator') : ''
            )) . '</span>';
            if ($stale && $canRun) {
                echo '<form method="post" action="' . htmlescape(self::pageUrl()) . '">';
                echo Html::hidden('release_stale', ['value' => 1]);
                echo Html::submit(__('Release stale lock', 'itsmmigrator'), ['name' => 'release', 'class' => 'btn btn-warning']);
                Html::closeForm(); // adds the CSRF token and closes the form
            }
        }
        echo '</div></div>';
    }

    private static function startPanel(): void
    {
        global $DB;
        $sources = [];
        foreach ($DB->request(['SELECT' => ['id', 'name', 'adapter'], 'FROM' => Source::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'name']) as $s) {
            $sources[] = $s;
        }
        if ($sources === []) {
            echo '<div class="alert alert-info">' . htmlescape(__('No active source. Add one first.', 'itsmmigrator')) . '</div>';
            return;
        }

        echo '<div class="card mb-3"><div class="card-body"><form id="itsm-run-form" onsubmit="return false">';
        echo '<div class="row g-3">';
        echo '<div class="col-md-4"><label class="form-label" for="itsm-source">' . htmlescape(__('Source', 'itsmmigrator')) . '</label><select class="form-select" id="itsm-source" name="sources_id">';
        foreach ($sources as $s) {
            echo '<option value="' . (int) $s['id'] . '">' . htmlescape($s['name'] . ' (' . $s['adapter'] . ')') . '</option>';
        }
        echo '</select></div>';

        echo '<div class="col-md-8"><label class="form-label">' . htmlescape(__('Modules', 'itsmmigrator')) . '</label><div>';
        foreach (Engine::IMPLEMENTED as $m) {
            echo '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="modules[]" id="itsm-mod-' . $m . '" value="' . $m . '" checked>'
                . '<label class="form-check-label" for="itsm-mod-' . $m . '">' . htmlescape($m) . '</label></div>';
        }
        echo '</div></div>';

        echo '<div class="col-md-3"><label class="form-label" for="itsm-limit">' . htmlescape(__('Limit per module (0 = all)', 'itsmmigrator')) . '</label>'
            . '<input class="form-control" type="number" min="0" id="itsm-limit" name="limit" value="50"></div>';
        echo '<div class="col-md-3"><label class="form-label" for="itsm-since">' . htmlescape(__('From (date)', 'itsmmigrator')) . '</label><input class="form-control" type="date" id="itsm-since" name="since"></div>';
        echo '<div class="col-md-3"><label class="form-label" for="itsm-until">' . htmlescape(__('Until (date)', 'itsmmigrator')) . '</label><input class="form-control" type="date" id="itsm-until" name="until"></div>';
        echo '<div class="col-md-3"><label class="form-label" for="itsm-basis">' . htmlescape(__('The dates are about', 'itsmmigrator')) . '</label><select class="form-select" id="itsm-basis" name="date_basis"><option value="updated">' . htmlescape(__('last update', 'itsmmigrator')) . '</option><option value="created">' . htmlescape(__('creation day', 'itsmmigrator')) . '</option></select></div>';
        echo '<div class="col-md-12"><label class="form-label">' . htmlescape(__('Options', 'itsmmigrator')) . '</label><div>';
        self::check('dry_run', __('Dry run (write nothing)', 'itsmmigrator'), true);
        self::check('full', __('Read everything (ignore the watermark)', 'itsmmigrator'), false);
        self::check('approvals', __('Import approvals (one more request per ticket)', 'itsmmigrator'), false);
        self::check('retry_attachments', __('Fetch again the attachments GLPI refused before (after raising its size limit or adding a document type)', 'itsmmigrator'), false);
        self::check('retry_failed', __('Retry failed', 'itsmmigrator'), false);
        self::check('create_missing_users', __('Create missing users', 'itsmmigrator'), false);
        self::check('ldap_lookup', __('Directory lookup for users', 'itsmmigrator'), true);
        echo '</div></div></div>';

        echo '<div class="mt-3">'
            . '<button type="button" class="btn btn-primary me-2" id="itsm-start"><i class="ti ti-player-play me-1"></i>' . htmlescape(__('Start', 'itsmmigrator')) . '</button>'
            . '<button type="button" class="btn btn-outline-danger" id="itsm-stop" disabled><i class="ti ti-player-stop me-1"></i>' . htmlescape(__('Stop', 'itsmmigrator')) . '</button>'
            . '</div></form>';

        echo '<div class="progress mt-3" style="height:1.25rem"><div id="itsm-bar" class="progress-bar" role="progressbar" style="width:0%">0%</div></div>';
        echo '<div class="mt-3" id="itsm-counters"></div>';
        echo '<pre id="itsm-log" class="mt-3 p-3 rounded" style="background:#1e1e2d;color:#00ff9d;min-height:8rem;max-height:24rem;overflow:auto;font-size:.85rem" aria-live="polite"></pre>';
        echo '</div></div>';

        echo Html::scriptBlock(self::script());

        echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title">' . htmlescape(__('Service catalog -> Forms', 'itsmmigrator')) . '</h3></div><div class="card-body">'
            . '<p class="text-muted">' . htmlescape(__('Creates one GLPI Form per service catalog item of the source selected above (its fields become questions). Every Form is created inactive: review it in Administration > Forms, then switch it on. An item already imported is never created again, so it is safe to click twice or to run it again.', 'itsmmigrator')) . '</p>'
            . '<button type="button" class="btn btn-outline-primary me-2" id="itsm-cat-count"><i class="ti ti-list-numbers me-1"></i>' . htmlescape(__('Count the catalog (writes nothing)', 'itsmmigrator')) . '</button>'
            . '<button type="button" class="btn btn-primary" id="itsm-cat-run"><i class="ti ti-forms me-1"></i>' . htmlescape(__('Import the catalog as inactive Forms', 'itsmmigrator')) . '</button>'
            . '<pre id="itsm-cat-log" class="mt-3 p-3 rounded" style="background:#1e1e2d;color:#00ff9d;min-height:3rem;max-height:14rem;overflow:auto;font-size:.85rem" aria-live="polite"></pre>'
            . '</div></div>';
        echo Html::scriptBlock(self::catalogScript());
    }

    private static function check(string $name, string $label, bool $checked): void
    {
        echo '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="' . htmlescape($name) . '" id="itsm-' . htmlescape($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="itsm-' . htmlescape($name) . '">' . htmlescape($label) . '</label></div>';
    }

    private static function history(): void
    {
        global $DB;
        echo '<div class="card"><div class="card-header"><h3 class="card-title">' . htmlescape(__('Run history', 'itsmmigrator')) . '</h3></div><div class="table-responsive"><table class="table table-hover card-table"><thead><tr>';
        foreach ([__('Run'), __('Source', 'itsmmigrator'), __('Runner', 'itsmmigrator'), __('Dry run', 'itsmmigrator'), __('Started', 'itsmmigrator'), __('Finished', 'itsmmigrator'), __('Status', 'itsmmigrator'), __('Counters', 'itsmmigrator')] as $h) {
            echo '<th>' . htmlescape($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $names = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => Source::getTable()]) as $s) {
            $names[(int) $s['id']] = $s['name'];
        }
        foreach ($DB->request(['FROM' => Run::getTable(), 'ORDER' => 'id DESC', 'LIMIT' => 15]) as $r) {
            echo '<tr><td>' . (int) $r['id'] . '</td><td>' . htmlescape((string) ($names[(int) $r['sources_id']] ?? '#' . $r['sources_id'])) . '</td>'
                . '<td>' . htmlescape((string) $r['runner']) . '</td><td>' . ((int) $r['dry_run'] === 1 ? htmlescape(__('Yes')) : htmlescape(__('No'))) . '</td>'
                . '<td>' . htmlescape((string) $r['started_at']) . '</td><td>' . htmlescape((string) ($r['finished_at'] ?? '')) . '</td>'
                . '<td>' . htmlescape((string) $r['status']) . '</td><td>' . htmlescape(self::summary((string) ($r['counters'] ?? ''))) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** "users 3 created, tickets 2 failed" from a stored counters JSON. Pure. */
    public static function summary(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return '';
        }
        $parts = [];
        foreach ($data as $module => $row) {
            $bits = [];
            foreach ((array) $row as $k => $n) {
                if ((int) $n > 0 && $k !== 'fetched') {
                    $bits[] = (int) $n . ' ' . $k;
                }
            }
            if ($bits !== []) {
                $parts[] = $module . ': ' . implode(', ', $bits);
            }
        }
        return implode('; ', $parts);
    }

    public static function pageUrl(): string
    {
        return '/plugins/itsmmigrator/front/run.php';
    }

    /** The catalog buttons: count, or start and then step until done. The browser holds only the token the server gave. */
    private static function catalogScript(): string
    {
        $ajax = json_encode('/plugins/itsmmigrator/ajax/catalog.php');
        return <<<JS
(function () {
    const AJAX = {$ajax};
    const log = document.getElementById('itsm-cat-log');
    const count = document.getElementById('itsm-cat-count');
    const run = document.getElementById('itsm-cat-run');
    let busy = false;
    function token() { const m = document.querySelector('meta[property="glpi:csrf_token"]'); return m ? m.getAttribute('content') : ''; }
    function say(t) { log.textContent += t + '\\n'; log.scrollTop = log.scrollHeight; }
    function post(data) {
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token() } })
            .then(r => r.text().then(t => {
                try { return JSON.parse(t); }
                catch (e) { throw new Error('HTTP ' + r.status + ', the server did not answer JSON: ' + t.replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').trim().slice(0, 200)); }
            }));
    }
    function line(c) { return Object.keys(c).map(k => k + ' ' + c[k]).join(', '); }
    function lock(on) { busy = on; count.disabled = on; run.disabled = on; }
    function sid() { return document.getElementById('itsm-source').value; }
    count.addEventListener('click', function () {
        if (busy) { return; }
        lock(true); log.textContent = ''; say('Reading the catalog (one request per item, this takes about a minute)...');
        post({ action: 'count', sources_id: sid() }).then(r => {
            if (!r.success) { say(r.message || 'Failed'); return; }
            say('Would be created: ' + line(r.counts)); say('Nothing was written.');
        }).catch(e => say(e.message)).finally(() => lock(false));
    });
    run.addEventListener('click', function () {
        if (busy) { return; }
        if (!window.confirm('Create the Forms now? They are created inactive.')) { return; }
        lock(true); log.textContent = ''; say('Reading the catalog (one request per item, this takes about a minute)...');
        const total = {};
        const add = c => Object.keys(c).forEach(k => { total[k] = (total[k] || 0) + c[k]; });
        function next(token) {
            return post(token ? { action: 'step', sources_id: sid(), token: token } : { action: 'start', sources_id: sid() }).then(r => {
                if (!r.success) { say(r.message || 'Failed'); return; }
                if (r.busy) { say('Another import of this source is running. Wait for it to end, then click again (nothing is lost, nothing is doubled).'); return; }
                add(r.counts); say('Batch done, ' + r.remaining + ' item(s) left.');
                if (r.done) { say('Done: ' + line(total)); say('Every Form is inactive: review it in Administration > Forms, then switch it on.'); return; }
                return next(r.token);
            });
        }
        next('').catch(e => say(e.message + ' (what is imported stays; click again to go on)')).finally(() => lock(false));
    });
})();
JS;
    }

    /** The run loop: start, then step until done. The run id is all the browser holds. */
    private static function script(): string
    {
        $ajax = json_encode(self::AJAX);
        return <<<JS
(function () {
    const AJAX = {$ajax};
    const form = document.getElementById('itsm-run-form');
    const log = document.getElementById('itsm-log');
    const bar = document.getElementById('itsm-bar');
    const counters = document.getElementById('itsm-counters');
    const start = document.getElementById('itsm-start');
    const stop = document.getElementById('itsm-stop');
    let runId = 0, stopping = false;

    function token() { const m = document.querySelector('meta[property="glpi:csrf_token"]'); return m ? m.getAttribute('content') : ''; }
    function say(lines) { (Array.isArray(lines) ? lines : [lines]).forEach(l => { log.textContent += l + '\\n'; }); log.scrollTop = log.scrollHeight; }
    function post(data) {
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: (data instanceof URLSearchParams) ? data : new URLSearchParams(data),
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': token() } })
            .then(r => r.text().then(t => {
                try { return JSON.parse(t); }
                catch (e) {
                    // Not JSON: say what the server sent (tags stripped), instead of a cryptic parse error.
                    throw new Error('HTTP ' + r.status + ', the server did not answer JSON: ' + t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200));
                }
            }));
    }
    function showCounters(c) {
        const mods = Object.keys(c || {});
        if (!mods.length) { counters.textContent = ''; return; }
        const keys = Object.keys(c[mods[0]]);
        let h = '<table class="table table-sm"><thead><tr><th>module</th>' + keys.map(k => '<th>' + k + '</th>').join('') + '</tr></thead><tbody>';
        mods.forEach(m => { h += '<tr><td>' + m + '</td>' + keys.map(k => '<td>' + c[m][k] + '</td>').join('') + '</tr>'; });
        counters.innerHTML = h + '</tbody></table>';
    }
    function finish(msg) { start.disabled = false; stop.disabled = true; runId = 0; if (msg) say(msg); }
    function loop() {
        if (stopping) { return post({ action: 'abort', run_id: runId }).then(r => { say('Stopped. What was written stays and the run can be resumed.'); finish(); }); }
        return post({ action: 'step', run_id: runId }).then(r => {
            if (!r.success) { finish('ERROR: ' + (r.message || 'step failed')); return; }
            say(r.logs || []); showCounters(r.counters);
            if (r.progress) { const pct = Math.round(100 * r.progress.index / Math.max(1, r.progress.of)); bar.style.width = pct + '%'; bar.textContent = r.progress.module + ' (' + pct + '%)'; }
            if (r.done) { bar.style.width = '100%'; bar.textContent = r.status; finish('Run finished: ' + r.status); return; }
            return loop();
        }).catch(e => { const id = runId; finish('ERROR: ' + e.message); if (id) { post({ action: 'abort', run_id: id }).then(() => say('The run was stopped and the lock released.')).catch(() => say('Could not stop the run: use Release stale lock after 15 minutes.')); } });
    }
    start.addEventListener('click', function () {
        const fd = new FormData(form); const data = new URLSearchParams();
        data.append('action', 'start');
        fd.forEach((v, k) => data.append(k, v));
        // An unchecked box is simply absent; the server defaults to a dry run only when dry_run is absent.
        if (!fd.has('dry_run')) { data.append('dry_run', '0'); }
        if (!fd.has('ldap_lookup')) { data.append('ldap_lookup', '0'); }
        log.textContent = ''; counters.textContent = ''; bar.style.width = '0%'; bar.textContent = '0%'; stopping = false;
        start.disabled = true;
        post(data).then(r => {
            if (!r.success) { finish((r.locked ? 'LOCKED: ' : 'ERROR: ') + r.message); return; }
            runId = r.run_id; stop.disabled = false;
            say((r.notes || []).concat(['Run ' + r.run_id + ' started' + (r.dry_run ? ' (DRY RUN)' : '') + ': ' + r.modules.join(', ')]));
            return loop();
        }).catch(e => finish('ERROR: ' + e));
    });
    stop.addEventListener('click', function () { stopping = true; stop.disabled = true; });
})();
JS;
    }
}
