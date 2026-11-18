# ITSM Migrator (`itsmmigrator`)

A GLPI 11 plugin that brings data from an external ITSM tool into GLPI, with GLPI staying the single source of truth.

- **Freshservice (API v2):** a one-time import of the history, before Freshservice is taken down.
- **ManageEngine ServiceDesk Plus on-premises (REST API v3):** an initial import plus a weekly delta sync. *Not built yet (M4).*

Status: release candidate `0.1.0-rc5`. See `CHANGELOG.md` for what each build contains and for the open items.

## What it guarantees

| Rule | How |
|---|---|
| The source is read-only | Only GET calls. The API key is read from the encrypted source row, never from a CLI option, an environment variable or a file. |
| One target entity per source | Everything the plugin creates goes into that entity with `is_recursive = 0`. Nothing lands in the root entity. A ticket that a business rule moves is put back. |
| Never a duplicate | A mapping table (`source`, `src_type`, `src_id`) is written right after every `add()`; children (followups, tasks, documents) are mapped one by one; there are natural-key fallbacks; one global lock for CLI, web and cron. |
| Existing users are matched, never changed | E-mail (all addresses, alias domains second), then login, then the directory, then a local user only with `--create-missing-users`. Ambiguity is `needs_review`, never a guess. |
| Existing users are never sent to the LDAP import | GLPI's import call re-activates an inactive user. The lookup searches read-only and imports only a person who is not yet a GLPI user. |
| Existing groups and categories are matched | What the target entity can already see (its own rows plus recursive rows of ancestor entities), nearest entity first. |
| Re-runnable | Resume (`in_progress`), `--retry-failed`, `--recreate-missing`. A second run with no source change changes nothing. |
| Tables survive uninstall | Dropping the maps would make a reinstall import everything again. Only `plugins:itsmmigrator:purge --confirm` drops them. |

## Install

```
cd <glpi>
php bin/console plugin:install itsmmigrator --force
php bin/console plugin:activate itsmmigrator
```

Never upgrade it from Setup > Plugins in the browser. GLPI 11 has no cron console command: the weekly task (`SdpSync`) is registered **switched off** until the SDP adapter exists, and would run with `php front/cron.php --force SdpSync`.

## Pages

| Page | Right | What it does |
|---|---|---|
| ICT Tools > ITSM Migrator > ITSM sources | plugin_itsmmigrator_config | The source list and the source form (the API key is a password field, never shown again). |
| ... > Runs | plugin_itsmmigrator_run | Start a run (a **dry run is ticked by default**), watch it, see which run holds the lock, release a **stale** lock, read the history. |
| ... > Needs review | plugin_itsmmigrator_run | Source users that could not be matched without guessing: pick the right GLPI user, or No GLPI user. Only the mapping is written, never the user row. |
| Ticket / Problem / Change > External references | _run or _config read | Every source the item came from: id, link back to it, how it got here, status, last change. Shown only when the item has references. |

A run from the browser takes the same global lock as the CLI and works in small batches (10 tickets, or 100 of the others, per request) so no request outlives the browser limit; its position lives in the server-side session, so the browser only holds the run id. Large first imports are still best run from the CLI.

## Commands

GLPI 11 only loads plugin commands named `plugins:<key>:<name>`, so these are **not** the `itsmmigrator:*` names of the specification.

| Command | What it does |
|---|---|
| `plugins:itsmmigrator:run` | Import or sync one source. Run it as a GLPI user: `--username=<login>`. |
| `plugins:itsmmigrator:verify` | Read-only consistency check. Exit code 0 = clean, 1 = something found. The gate before production. |
| `plugins:itsmmigrator:purge` | Drop the plugin tables, or one source's map rows (`--source=<id>`). Does nothing without `--confirm`. Migrated GLPI data is never deleted. |

`run` options: `--source`, `--modules` (users, groups, categories, tickets), `--since`, `--dry-run`, `--limit`, `--retry-failed`, `--recreate-missing`, `--force-unlock`, `--create-missing-users`, `--activate-created-users`, `--fallback-user`, `--assign-profiles`, `--no-ldap-lookup`.

## Sources

Add a source under ICT Tools > ITSM Migrator: name, adapter, `https://` base URL, API key (a password field, never shown again; empty on update keeps the stored one), optional CA bundle for an internal CA, **target entity**, schedule, active. Only one **active** source per adapter is supported (the map key has no source id).

## Rules this plugin follows that other suite plugins may also need

- **Creating tickets from CLI or cron:** `Ticket::add` silently drops `_users_id_*` and `_groups_id_*` when the session's active entity is not the ticket's entity (core `transformActorsInput()` skips assign actors unless `canAssign()`). Create the ticket first, then add `Ticket_User` and `Group_Ticket` rows and check each result.
- **Status and dates last:** write status, `date`, `date_mod`, `solvedate`, `closedate` with `$DB->update()` after the children exist, or GLPI recalculates them.
- **Notifications:** every write passes `_disablenotif`.
- **Strict MySQL:** no `TEXT` with a `DEFAULT`; never `''` into a `TIMESTAMP`; install runs on every upgrade.

## Tests

```
php tests/run_all.php            # offline, no GLPI, no database
php -c <php.ini> tools/staging_integration_m2.php   # STAGING ONLY: users, groups, categories, LDAP step, lock
php -c <php.ini> tools/staging_integration_m3.php   # STAGING ONLY: tickets and their children
php -c <php.ini> tools/staging_integration_m7.php   # STAGING ONLY: verify finds seeded defects
```

The offline suites need the `mbstring` extension. The staging tools create throwaway data named `itsmtest*` and remove it; never run them on production. `tests/` and `tools/` are not shipped in the zip.

## Documentation

The user manual is written by Copilot (hub §0 rule 9). The step-by-step deployment is `docs/runbook-v0.1.0-rc5.md`.
