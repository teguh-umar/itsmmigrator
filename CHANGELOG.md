# Changelog

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
