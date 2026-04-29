# Changelog

All notable changes to DoliModuleManager are documented here.

## 1.8.0

### Added
- **"My DoliStore purchases" tab** (`admin/purchases.php`). Lists modules
  bought on dolistore.com and installs them with one click. Two-pass scrape
  against the buyer-side pages (`order-history.php` → `order-details.php`),
  download via the existing `_service_download.php?t=paied` endpoint with
  the order ref + user id from the per-product link.
- **DoliStore session helper** (`class/DMMDolistoreSession.class.php`).
  Auth uses a session cookie pasted from the browser (preferred) or
  email + password fallback (encrypted via `dolEncrypt`, same pattern as
  `DMMToken`). All paths fail closed: missing creds, expired session,
  network down, or `curl`/`dom` extension absent show a user-friendly
  message and never crash the dashboard or marketplace.
- **DoliStore credentials block** in `admin/setup.php` with a "Test
  connection" button that saves and verifies in one round-trip (no
  footgun where the test runs against stale settings).

### Changed
- **`DMMClient::getInstalledVersion()`** now matches three descriptor
  patterns: literal string, `file_get_contents(__DIR__.'/../../VERSION')`,
  and `self::VERSION` constants. Fixes modules like Change Thirdparty
  that previously stored `dolistore-{id}` as `installed_version` instead
  of the real semver.

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
