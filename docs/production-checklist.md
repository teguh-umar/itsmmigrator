# Production checklist: itsmmigrator (ITSM Migrator)

A short, ordered checklist for the first real import into a production GLPI. It adds to `docs/runbook-v0.1.0-rc36.md` (install, source form, dry runs); read that first. This page covers what was learned on a staging instance and is easy to forget on the real one.

Replace `<glpi>`, `<webuser>`, `<id>` (source id), `<login>` (a GLPI login with rights on the target entity) and `<entity>` with your own values. Keep every file that holds real names, addresses or ticket text (CSV exports, backups, logs) **outside** the repository.

## A. Decide before you start

- [ ] **Target entity.** A dedicated entity below the one you want to protect, never the root entity. Everything the plugin creates stays in it.
- [ ] **Scope.** The ticket categories to import (source form, "Import only these ticket categories") and the start date ("Import tickets updated since"). A ticket outside the category filter is skipped without a map row, so widening the filter later picks it up.
- [ ] **One run at a time.** Never start a second run, never swap the plugin folder, never restart the web server while a run is live. A run can be resumed; a half-replaced plugin cannot.
- [ ] **The API key** is typed once into the source form. Never in a chat, a file, a command line or an environment variable.
- [ ] **A database backup** taken just before the first real run. The plugin tables are part of it; restore them together with GLPI and then run `plugins:itsmmigrator:verify`.

## B. GLPI prerequisites (before the first ticket import)

- [ ] **GLPI version.** The ticket status list must contain **Approval** (status 10). GLPI 11.0.13 has it. Check: in the ticket form the Status dropdown shows "Approval". Without it a source status of "Waiting on Approval" cannot be mapped.
- [ ] **Document types.** The plugin never creates a document type (it only reads the list of uploadable ones). Add, under Setup > Dropdowns > Documents > Document types, with "Authorized upload" on:
  - `ph_p`: required. A PHP attachment (`.php`, `.phtml`, `.phar`, `.php5`...) is stored under this harmless extension, its real name stays in the document title and comment.
  - Any other extension the source uses. Without it that attachment is skipped and a private note on the ticket says why (see section E for importing it later).
- [ ] **Maximum document size** (the GLPI setting `document_max_size`, in MB) at least as large as the biggest attachment you want. A larger file is skipped with a private note.
- [ ] **Directory (LDAP).** People are matched by e-mail or login. An unmatched person is looked up read-only in the configured directory and is never created as a local user unless you pass `--create-missing-users`. A person who cannot be matched is kept as text in the ticket (the requester line, or a private approval note), never guessed.
- [ ] **Profiles.** Only Super-Admin gets the plugin rights at install; add other profiles under Administration > Profiles > ITSM Migrator.

## C. Assets before tickets (only if monitor tickets should link to assets)

A monitor ticket ("NMS" alarms naming a device) is linked to the GLPI asset of that name when the asset exists, otherwise it gets a private note. So the assets must exist first.

- [ ] **Custom asset definitions** (Setup > Asset definitions) exist for every kind you import (for example network devices, wireless access points, storage). A definition cannot be copied from another GLPI by this plugin.
- [ ] **Network devices and computers** from a CSV exported from the old GLPI asset list (columns Name, Location, Status, Type are used):

```bash
cd <glpi>
sudo -u <webuser> php bin/console plugins:itsmmigrator:import-assets --username=<login> --file=<csv outside the repo> --itemtype=NetworkEquipment --entity=<entity> --dry-run
sudo -u <webuser> php bin/console plugins:itsmmigrator:import-assets --username=<login> --file=<csv outside the repo> --itemtype=NetworkEquipment --entity=<entity>
```

  `--itemtype` also takes `Computer`, `Printer`, `Phone`, `Peripheral`, `Monitor` or the system name of an asset definition. Names already present in the entity are left alone (rows with a repeated name count as "exists"). Run the dry run first and check the counts.
- [ ] **Mist (wireless).** Map each Mist site to an entity on the Mist connection form; a site left unmapped (entity "not mapped") keeps its devices as site-unmapped on purpose. Entities must be below the root.

## D. The import

- [ ] **Install and verify** as in the runbook, sections 0 to 2, then `plugins:itsmmigrator:verify` (expect `Verify: clean`).
- [ ] **Dry run** of everything you will run for real, with the same options:

```bash
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<login> --since=<YYYY-MM-DD>T00:00:00Z --approvals --dry-run --limit=50
```

  Read the log: `unmapped status/priority/type` lines mean a label the default maps do not know. "Waiting on Approval" maps to Approval by default; other labels can be set in the source's `status_map` (JSON, `{"<label>": <GLPI status number>}`).
- [ ] **Small pilot, then verify:** the same command without `--dry-run` and with `--limit=50`, then `plugins:itsmmigrator:verify --source=<id>`. Open two or three imported tickets.
- [ ] **Memory.** The first full import can take hours, and the PHP process must not run out of memory. Give it a memory limit (for example `-d memory_limit=768M`), keep other heavy applications closed, and keep free memory in mind on a small machine. A run killed by the operating system leaves a run row that you release with `--force-unlock` after its heartbeat is older than 15 minutes; the next run resumes.
- [ ] **Full run:**

```bash
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<login> --since=<YYYY-MM-DD>T00:00:00Z --approvals
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<login> --since=<YYYY-MM-DD>T00:00:00Z --approvals --retry-failed
sudo -u <webuser> php bin/console plugins:itsmmigrator:verify --source=<id>
```

  Good: `failed` 0, `verify` clean. A second run with nothing changed at the source reports 0 created. If the source key is revoked, three consecutive 401/403 answers stop the run by themselves.
- [ ] **Service catalog (optional).** `plugins:itsmmigrator:import-catalog --username=<login> --source=<id> --dry-run`, then without `--dry-run` (or the button on the Runs page). It creates GLPI Forms that are **inactive** (and not drafts); an administrator activates the ones to use. Running it again creates no duplicate.

## E. After the import

- [ ] **Monitor tickets and assets:**

```bash
sudo -u <webuser> php bin/console plugins:itsmmigrator:link-assets --username=<login> --source=<id> --dry-run
sudo -u <webuser> php bin/console plugins:itsmmigrator:link-assets --username=<login> --source=<id>
```

  A ticket whose device is not an asset keeps a private note; when the asset appears later, run it again and the note is replaced by the link.
- [ ] **Skipped attachments.** After adding document types or raising the size limit, run the import again with `--retry-skipped-attachments`; each earlier private "not imported" note becomes "imported later".
- [ ] **Mail-bounce notices** are shortened on import (diagnostic data and header blocks are dropped; the failure statement stays). For tickets imported by an older build: `plugins:itsmmigrator:clean-bounces --username=<login> --entity=<entity> --dry-run`, then with `--backup=<file outside the repo>`. Nothing is deleted.
- [ ] **Status after import.** A later run moves an already-imported ticket **forward only** (New/Assigned/Pending to Approval to Solved to Closed), only while it is still open or pending and was not edited in GLPI after the source's last update. It never goes backwards and never reopens a solved or closed ticket.
- [ ] **Final verify** with no `--source`; set the source inactive when the migration is finished (keep its map rows).

## F. Rollback

`plugins:itsmmigrator:purge` removes what a source created and is meant for a test, not for production. For production, restore the database backup from section A (GLPI and plugin tables together) and run `plugins:itsmmigrator:verify`.
