# Runbook: itsmmigrator (ITSM Migrator) 0.1.0-rc29

Run these steps in order. Every step says what a good result looks like. Stop at the first step that does not match and send the output back; do not skip ahead.

Facts used throughout:

- Command names are `plugins:itsmmigrator:run`, `:verify` and `:purge` (GLPI 11 only loads plugin commands under `plugins:<key>:`).
- There is no `glpi:cron` console command in GLPI 11. A cron task runs with `front/cron.php --force <TaskName>`.
- Never upgrade this plugin from Setup > Plugins in the browser; use the console commands below.
- **Never put the Freshservice API key in a chat, a ticket, a file, a command line or an environment variable.** It is typed once into the source form and stays encrypted in the database.
- The plugin only ever sends GET requests to the source. Keep the first runs small (`--limit`), the source is a live service.
- Substitute your own paths and web-server user for `<glpi>` and `<webuser>`.

## 0. Put the build in place

Unpack `itsmmigratorv0.1.0-rc29.zip` so the folder is `<glpi>/plugins/itsmmigrator/` (the archive root is already `itsmmigrator/`), owned by the web-server user.

```bash
cd <glpi>/plugins
sudo unzip -o ~/itsmmigratorv0.1.0-rc29.zip
sudo chown -R <webuser>:<webuser> <glpi>/plugins/itsmmigrator
ls <glpi>/plugins/itsmmigrator/setup.php
```

Good: the last command prints the path. If an older `itsmmigrator` folder exists, move it out of `plugins/` first (not to a sibling folder inside `plugins/`).

## 1. Back up

Back up the GLPI database before the first import. The plugin tables live in it; always restore them **together** with GLPI, then run `plugins:itsmmigrator:verify`.

## 2. Install and activate

```bash
cd <glpi>
sudo -u <webuser> php bin/console plugin:install itsmmigrator --force
sudo -u <webuser> php bin/console plugin:activate itsmmigrator
sudo -u <webuser> php bin/console plugin:list | grep -i itsmmigrator
sudo -u <webuser> php bin/console cache:clear
```

Good: install and activate report success; `plugin:list` shows `0.1.0-rc29` and `Enabled`. Clear the cache before opening any page of the plugin. Then log out and in again: GLPI reads profile rights and builds the menu at login, so ICT Tools > ITSM Migrator does not appear in an older session.

Check the three tables, the rights and the cron task:

```sql
SHOW TABLES LIKE 'glpi\_plugin\_itsmmigrator\_%';
SELECT name, rights FROM glpi_profilerights WHERE name LIKE 'plugin_itsmmigrator_%' AND rights > 0;
SELECT name, itemtype, frequency, mode, state FROM glpi_crontasks WHERE name = 'SdpSync';
```

Good: three tables (`sources`, `maps`, `runs`); `plugin_itsmmigrator_config` and `_run` with a non-zero mask for the Super-Admin profile only; one cron row, frequency `604800`, mode `2`, **state `0`** (switched off until the SDP adapter exists).

To let another profile use the plugin: Administration > Profiles > that profile > tab ITSM Migrator.

## 3. Create a test entity and a source

1. Create an entity for the import below the entity you want to protect (for a first try, a throw-away test entity). Its name is yours to choose; it must not be the root entity.
2. ICT Tools > ITSM Migrator > Add. Adapter `freshservice`, base URL `https://<your-domain>`, **API key: type it into the password field**, target entity = the test entity, schedule `manual`, active. Save.
3. Only one active source per adapter is accepted. A second one is refused with the name of the first.

Good: the source appears in the list. The key is never shown again; leaving the field empty on a later save keeps it.

## 4. Test the connection and take a first look

```bash
cd <glpi>
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<glpi-login> --dry-run --limit=50
```

Run it as a real GLPI user: `--username=<glpi login>` is required (the CLI has no session of its own, and that user's profile decides which entities can be written to). `UNMAPPED status "..."` lines mean the account uses a status wording the defaults do not know (Freshservice accounts add their own, for example "Waiting on Approval"): a best guess is used and shown; set the real mapping in the source's status map (JSON, label to GLPI status) before the real run.

Good: it prints `Run <n> on source ...`, one block per module, and a counter table. A bad key or an unreachable host stops at "Connection test failed" before anything else happens. A dry run writes nothing (no GLPI items, no map rows) and does not fetch ticket children.

Look at the log lines: `unmapped status/priority/type "..."` mean the source uses a label the default maps do not know (edit the maps on the source). Record the first real responses as scrubbed fixtures in `tests/fixtures/` (names, e-mails, domains and ticket text removed) so the adapter can be checked against real data.

## 5. Pilot, then full, in this order

In the source form of a saved source, **Show the categories of this source** lists the source's category tree (paths only, read from the source with one GET, nothing stored or logged) so the paths can be clicked instead of typed. A click adds the path to the box above; nothing is saved until you press Save.
**Import in phases.** The source form has **Import tickets updated since**. Phase 1: set it to the newest period (for example `2026-01-01`), run a dry run with `--limit=50`, then the real run. A later, wider phase: lower the date (for example `2025-01-01`) and run with **Read everything** (`--full`, or the box on the Runs page): the tickets already imported are skipped by the map, only the older ones are created. A plain run after a phase is a delta from the watermark and never goes below the date. One query returns at most 30,000 tickets, so a big period is read in windows (the log says "Window ended at the 30000 ticket cap: the next window starts at ..."); this is normal. A long read needs free memory on the machine running it: with little free RAM use `--limit` for a trial, and run the full import where memory is free.
**Scope the first import.** In the source form, "Import only these ticket categories" takes one category path per line (for example `Hardware > Laptop`; a path includes everything below it). The same can be given per run with `--category`. Use the wording the dry run shows for the categories; tickets of other categories are left out and nothing remembers them, so widening the list later picks them up. A run with a category filter never moves the watermark. Without a filter every category is imported.

```bash
# people, groups and categories first: tickets need their maps
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<glpi-login> --modules=users,groups,categories --limit=50
# a small ticket pilot
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<glpi-login> --modules=tickets --limit=50
sudo -u <webuser> php bin/console plugins:itsmmigrator:verify --source=<id>
```

Good: counters show `created` for new items and `matched` for people and groups that already existed; `failed` is 0; `verify` ends `Verify: clean` with exit code 0. Open two or three imported tickets: title `[FS#<id>] subject`, original dates and status, requester and assignee, followups with their private flag, tasks, time, attachments.

Users: `needs_review` rows (an e-mail shared by two GLPI users, or e-mail and login pointing at different users) are never guessed; they use the fallback user until resolved. Without `--create-missing-users` an unmatched person is `skipped` and their tickets use `--fallback-user=<id>`.

Then the full run (drop `--limit`), then retry what failed and verify:

```bash
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<glpi-login>
sudo -u <webuser> php bin/console plugins:itsmmigrator:run --source=<id> --username=<glpi-login> --modules=tickets --retry-failed
sudo -u <webuser> php bin/console plugins:itsmmigrator:verify --source=<id>
```

The source's **watermark** (visible in the source list) moves after a complete run: later runs read only tickets updated since it, minus 2 hours. Use `--full` to read everything again, `--retry-failed` for failed tickets (it reads everything on purpose: a failed ticket can be older than the watermark). A dry run and a `--limit` run never move it.

A second full run with no change at the source must report 0 created and 0 matched. If a run is interrupted, run it again: it resumes (`in_progress` rows reuse the GLPI item already added) and never adds a second parent. If a lock is left behind (the message says "heartbeat is older than 15 minutes"), `--force-unlock` releases it; a live run is never aborted.

## 6. The directory step (users)

With directories configured in GLPI (Setup > Authentication > LDAP), unmatched people are looked up read-only and imported as LDAP users **only if they are not yet GLPI users**. An existing user, active or not, is never passed to GLPI's import call (it would re-activate them). Imported users lose any profile granted outside the target entity. Pass `--no-ldap-lookup` to skip the step. A directory that cannot be reached or bound is skipped for the rest of the run.

Before trusting it, test with the few people you need: a read-only bind account per directory and Test in the directory form first. **Never run a full directory synchronisation as a test.**

## 7. After the import

1. Run `plugins:itsmmigrator:verify` once more with no `--source`. Good: clean, exit code 0.
2. Set the Freshservice source inactive in the source form (keep its map rows: they are what links later sources to the same tickets).
3. Do not run `plugins:itsmmigrator:purge`. It exists for rollback of a test and re-imports everything afterwards.

## 8. Running from the browser (optional)

ICT Tools > ITSM Migrator > Runs. Pick the source and the modules, leave **Dry run** ticked for the first look, set a limit, Start. The page works through the run in small batches and shows the log and the counters; Stop ends it and what was written stays (a later run resumes). The page needs `plugin_itsmmigrator_run` with Update.

- If another run (CLI, cron or another browser) holds the lock, Start says who and since when. A run whose heartbeat is older than 15 minutes is shown as probably dead: **Release stale lock** frees it. A live run is never released.
- The browser run needs access to the source's target entity. An administrator without it is told so before anything starts.
- Needs review lists users the plugin would not guess. Pick the right GLPI user (a candidate, or any user) or "No GLPI user". Tickets already imported keep the fallback user; later imports use the choice.
- A ticket that came from a source shows **External references** with a link back to it.

Good: the page never shows an API key, a failed connection is reported on the page, and `plugins:itsmmigrator:verify` still ends clean after a browser run.
## What this build does not do

ServiceDesk Plus (initial import, weekly sync, cross-source linking updates, read-only guard) is a later milestone. Freshservice group membership is not read, and the source watermark is not advanced yet. The Freshservice field names are written from the API documentation and have not been checked against a real account.
