# Changelog

All notable changes to DoliModuleManager are documented here.

## 1.9.2

### Fixed
- **AJAX loader no longer jumps on each new line.** The loading overlay box was
  vertically centered while its log grew, so the whole box drifted upward as
  lines arrived. The log now has a fixed height (stable box), and auto-scroll
  only sticks to the bottom when the user is already there — scroll up to read
  and your position is kept.

## 1.9.1

### Fixed
- **Empty catalog on a fresh install.** The default hub is now imported once
  on the first dashboard load (guarded, before the preflight redirect), so a
  new install lands on a populated catalog — DMM itself included, since the
  default hub lists `nikube/DMM`. Done outside `init()` so a slow or
  unreachable hub can never block module activation.

## 1.9.0

### Added
- **Branch selection per module.** On the module page (developer mode), a
  branch picker lists the repository's real branches, loaded on demand via
  AJAX. Following a branch lets DMM track and install a module that has no
  GitHub release, via HEAD-SHA tracking.
- **Local module init-scan.** A "Scan installed modules" action (Advanced
  tab) walks `custom/`, matches each module to a known source (local
  `dmm.json` repository or github `url`, a hub, or DoliStore) and registers
  matched ones; unmatched modules are reported without being inserted.
  Idempotent.
- **Non-blocking marketplace.** The first DoliStore catalog load no longer
  freezes the page for ~30s: it renders instantly with a loader, warms the
  cache in the background, and reloads onto the warm cache. Includes a
  Cancel button that returns to the dashboard while the cache keeps building.

### Changed
- **Settings split into three top-level tabs.** *Settings* (simple
  fine-grained token + DoliStore credentials), *Advanced* (general options,
  developer mode, community YAML, backups, preflight, local scan) and
  *Sources* (hubs, full token management, public repositories).

### Fixed
- **Marketplace warm-up no longer blocks other pages.** The cache build
  releases the PHP session lock early, so the dashboard and Cancel respond
  immediately instead of waiting for the catalog download.

## 1.8.3

### Added
- **AJAX loader for external checks.** Long GitHub/DoliStore-style actions
  now show a loading overlay instead of leaving the page apparently idle.
- **"Check installed" dashboard action.** Verifies only installed modules,
  avoiding needless checks for catalog entries that are not deployed.
- **Dev branch declared in `dmm.json`.** DMM can discover and switch its own
  module row to the `dev` update channel.

### Changed
- **GitHub update checks use cached ETags.** Release checks now send
  `If-None-Match` and reuse local cache data on `304 Not Modified`, avoiding
  a manifest fetch when nothing changed.
- **AJAX check log is module-focused.** The loader reports checked modules
  with `- OK` or the returned error, without noisy setup/progress lines.

## 1.8.2

### Fixed
- **Dev channel: Install/Update button missing.** When a module was switched
  to the `dev` channel, `cache_latest_compatible` is rewritten as a
  `dev:<sha>` pseudo-version. The visibility check used `version_compare`
  which doesn't understand that format, so it always returned 0 — no
  Update button ever appeared, and the user was stuck on stable. Now the
  check compares the raw values on dev channel; any SHA mismatch surfaces
  the Update.
- **Dev channel: install URL was broken.** The confirmation dialog passed
  `&tag=v<cache_latest_compatible>` to the install handler. On dev this
  produced `tag=vdev:abc123`, which the install handler treated as a real
  tag and sent to GitHub's `/tarball/{ref}` endpoint, where it 404'd. The
  handler already resolves `tag` from `branch_dev` when empty — the
  dialog now passes no tag on dev, so the resolution kicks in correctly.
- **Dev channel: button label readability.** "Install vdev:abc123" replaced
  with "Install develop@abc123" (branch name + short SHA).

## 1.8.1

### Added
- **Compatibility column on the purchases tab.** Each purchased module
  is cross-referenced with the public DoliStore catalog (already cached
  24 h on disk) to show its `dolibarr_min`/`dolibarr_max` range. Green
  badge when `DOL_VERSION` fits, red otherwise. Modules outside the
  range get an explicit red "Install anyway" button instead of a
  one-click Install.
- **`dmm_check_dolibarr_compat()`** helper in
  `lib/dolimodulemanager.lib.php` — tolerant of "V14"/"V23"/null
  variants and silently swaps inverted ranges (vendor typos like
  `min=V23, max=V6`).

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
