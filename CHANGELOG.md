# Changelog

## 0.1.0-rc2

- **M2: the engine core, still without a source adapter.** Nothing can be imported yet (the Freshservice adapter is M3); this build adds and tests the machinery every adapter will use.
- **One active source per adapter (orchestrator decision, 2026-11-18).** The maps key stays `(source, src_type, src_id)` as spec §3 has it, so `Source` refuses a second active source on the same adapter and says which one to deactivate first. Changing the key to include `sources_id` is a schema change, made only if two sources of one adapter are really needed.
- **`MapRepository`**: lookup by source item, by GLPI item, by `origin_ref`; `begin()` writes the `in_progress` row and, on a lost race on the unique key, returns the other runner's row; `markOk/Failed/Skipped/Orphan/NeedsReview`; `decide()` is the spec §5 rule 1 table (create / resume / skip / retry).
- **`Lock`**: the global single-runner lock. A `running` row in `runs` is the lock (a web run spans many requests), a file lock only makes check-and-insert atomic, a heartbeat per batch proves the owner is alive. A heartbeat older than 15 minutes is stale but still blocks until forced; a live run is never aborted from outside. The refusal names source, runner and start time.
- **`Engine`**: modules in dependency order (users, groups, categories implemented; kb, assets, tickets, problems and changes are reported "not built yet"), `--limit`, dry run, `Engine::$writing` flag for the future TicketGuard, lock released in `finally`, counters stored on the run row. The source watermark is not advanced yet (it belongs to the ticket modules, M3/M5).
- **Writers**: `UserWriter` (e-mail on all addresses, alias domains second, then login; ambiguity -> `needs_review` listing both candidates; a matched user is never modified; local user only with `create_missing_users`, inactive and flagged "Imported from ..." with the leak guard; `assign_profiles` none / requester_only / all adds only the exact missing Profile_User row), `GroupWriter` and `CategoryWriter` (match what the target entity can see: its own rows plus recursive rows of ancestor entities, nearest first; create in the target entity with `is_recursive = 0`; members only for mapped users, and only into a parent's group when `add_members_to_matched_groups` is on). Resume and retry reuse the GLPI item already recorded in the row.
- **LDAP lookup (spec §4a step 3) is not built.** `LdapLookupInterface` is in place, the engine runs without an implementation and logs once that the step was skipped. It needs a verified `AuthLDAP` import call and an LDAP server to test against.
- **`plugins:itsmmigrator:purge`** works: it drops the three plugin tables (or one source's map rows with `--source`), never touches migrated GLPI data, and does nothing without `--confirm`. `run` and `verify` still refuse.
- `tests/` and `tools/staging_integration_m2.php` stay in the repository but are no longer in the zip. The tool runs the engine against a throwaway entity tree with a fake adapter on **staging only** (45 checks: matching, isolation, idempotency, resume, retry, lock, leak guard).

## 0.1.0-rc1

- **M1 scaffold.** Plugin key `itsmmigrator`, PSR-4 `GlpiPlugin\Itsmmigrator\` -> `src/`, GLPI 11.0.0 - 11.0.99.
- **Tables** `glpi_plugin_itsmmigrator_sources`, `_maps`, `_runs` (spec §3). Install keeps existing tables; **uninstall drops nothing** (only the future `purge --confirm` will).
- **Source** (`CommonDBTM`): list and form under the shared ICT Tools sector (menu order 85). One target entity per source (must be below the root entity), never recursive, `https://` base URL only. The API key is stored encrypted (GLPIKey), registered through the `secured_fields` hook, and never rendered back; an empty value on update keeps the stored key.
- **Rights** `plugin_itsmmigrator_config` and `plugin_itsmmigrator_run`, with a Profile rights matrix. Super-Admin (the profile holding UPDATE on core `config`) receives full rights on every install and upgrade; other profiles get nothing.
- **Shared suite files** `lib/pgnsuite/` at ConfigTab r5 (`46feaa6c`), plus `front/pgnsuite.settings.php`; both marked `-text`. Section on Setup > PGN Suite (order 85); no AssetTab.
- **Cron** `GlpiPlugin\Itsmmigrator\Cron\SyncTask::SdpSync`, weekly, external mode. It is registered **switched off** and does nothing until the engine exists (M5).
- **CLI** `plugins:itsmmigrator:run`, `:verify`, `:purge` are declared with their options but refuse to run (engine in M2, checks in M7). GLPI 11 only loads plugin commands named `plugins:<key>:<name>`, so these differ from the `itsmmigrator:*` names in spec §10.
- Not in this build: engine, adapters, writers, External references tab, TicketGuard, run page.
- Release candidate: never pushed, no GitHub Release.
