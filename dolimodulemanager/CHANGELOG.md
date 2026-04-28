# Changelog

All notable changes to DoliModuleManager are documented here.

## 1.7.0

### Added
- **Marketplace tab.** New `admin/marketplace.php` aggregates the DoliStore
  catalog (~1500 modules, scoped to category 67 "Modules/Plugins") with
  one-click install for free modules. Promoted to a top-level tab next to
  Dashboard and Settings.
- **DoliStore as a module source.** `source='dolistore'` rows live next to
  the existing GitHub/Hub/Community sources. Update checks query the
  DoliStore catalog API (cached 24 h on disk). Install pipes the free ZIP
  through `_service_download.php?t=free&p={id}` (User-Agent + Referer
  required upstream — without them every product returns the 12-byte
  string "paiedProduct").
- **`dolistore_id` column** on `llx_dmm_module` (migration
  `update_1.6.8-1.7.0.sql`).
- **GitHub Actions release workflow** at `.github/workflows/release.yml`.
  Tag a `v*.*.*` to publish a GitHub Release with the
  `module_dolimodulemanager-X.Y.Z.zip` attached. Cross-checks the tag
  against the descriptor version and the dolistore.yaml / dmm.json
  support matrix before building.

### Changed (behavior — read carefully when upgrading)
- **`auto_migrate` defaults to `1`.** After install or update, DMM now
  runs the module's `init()` automatically — no more "click Install,
  then go reactivate the module from Configuration → Modules". Set the
  toggle to off in DMM → Settings if you prefer the previous popup-based
  flow. Existing installs that explicitly set `auto_migrate=0` keep
  their setting.
- **Dashboard default filter is now `installed`** (was `all`). The
  marketplace tab is the catalog now; the dashboard is for managing
  what you have.
- **Source URL column** in the dashboard links to
  `dolistore.com/product.php?id=X` for DoliStore-sourced modules instead
  of a synthetic `github.com/dolistore:NNN` (which 404'd).
- **Compatibility window widened to V14–V23** in `dmm.json` and
  `dolistore.yaml`. The descriptor still declares `need_dolibarr_version
  = (14, 0, 0)`.

### Fixed
- **Tokenless modules no longer crash the check flow.** Public GitHub
  repos, hub-imported community modules and DoliStore rows all pass
  `null` as token; `DMMClient::checkUpdate` resolves the right anonymous
  path. Auto-check no longer skips tokenless rows entirely.
- **Backup FK is nullable** (`ON DELETE SET NULL`). Backups can outlive
  their module row — useful when DMM renames a row to its canonical
  descriptor id mid-install, or when a row is manually deleted but the
  on-disk backup is still wanted for the rescue script.
- **DoliStore registry now persists `cache_latest_version` immediately**
  on add. The dashboard shows the upstream version without needing a
  manual Check.
- **Catalog scope.** Books, PDFs, skins and goodies are no longer listed
  in the marketplace — they were the source of `not_a_zip` errors when
  picked.

### Network behavior to be aware of
- `MAIN_ENABLE_EXTERNALMODULES_COMMUNITY` was already on by default since
  1.6.1. With 1.7.0 the marketplace tab also fetches the DoliStore
  public API (1500+ products, paginated, cached 24 h locally). Both are
  outbound HTTPS to public hosts. Disable from the Marketplace header
  toggle if the install must remain offline.
