# Changelog

All notable changes to DoliModuleManager are documented here.

## 2.0.0

### Added
- **Dashboard shortcut to the native module setup.** Each installed module row
  now has a puzzle-piece icon linking to `admin/modules.php?search_keyword=<module>`,
  opening Dolibarr's module configuration page pre-filtered on that module
  (activate/deactivate, setup page, permissions).

### Fixed
- **Branch selector required re-selecting a branch.** "Load branches" pre-selected
  `branch_dev` in the channel dropdown even when the module was on the stable
  channel (`branch_dev` is populated from the manifest either way). The visible
  value silently desynced from the real channel, so picking that branch fired no
  `change` event and nothing happened until you selected another entry and came
  back. The AJAX now only flags a branch as current on the dev channel.
- **No Update button right after switching channel.** `setchannel` invalidated the
  version cache and redirected, hiding the Install/Update button until a manual
  "Check now". The channel switch now re-runs the update check before redirecting,
  so the button shows immediately with the new `branch@sha` (or release) target.

## 1.11.1

### Fixed
- **Duplicate hubs in the Sources tab.** The same `dmmhub.json` referenced by
  different URL forms — `raw.githubusercontent.com/.../dmmhub.json`,
  `api.github.com/repos/.../contents/dmmhub.json`, `github.com/.../blob/...` — was
  stored as several distinct hubs, showing two or three identical rows. A new
  `dmm_hub_identity()` canonicalizes every form to `github:owner/repo`; add,
  toggle, remove, token-discovery and sub-hub registration all compare on that
  identity, and `dmm_save_hubs()` deduplicates centrally (re-saving an existing
  hub list also cleans up any duplicates already stored).
- **"Nothing happens when I add a token."** When a token exposes no repositories
  (invalid/expired, missing `repo`/Contents permission, or a fine-grained token
  with no repositories selected), discovery silently reported "0 repos". It now
  shows an actionable warning explaining exactly what to check. The token was
  always passed correctly to the GitHub API — this was a missing diagnostic, not
  an auth bug.

## 1.11.0

### Security
- **GitHub token exfiltration via hub URLs (critical).** `fetchHub()` retried a
  failed fetch by replaying every stored GitHub PAT as a `Authorization: Bearer`
  header against the *same URL* — a hub is an admin-supplied URL and can list
  sub-hubs that were auto-fetched, so a hostile hub could harvest all tokens.
  Tokens are now attached only when the URL targets GitHub (`isGithubHost()`),
  redirects are no longer followed while carrying a token, and discovered sub-hubs
  are registered disabled and never auto-fetched until the admin enables them.

### Fixed
- **Restore/update could destroy a module (major).** `DMMBackup::restore()` and the
  in-place update deleted the live module *before* copying the replacement, so a
  failed copy left the module missing. Both now stage into a temp dir and rename-swap
  atomically, rolling back on failure. Regular updates also drop files removed
  upstream instead of merging over them (self-update stays copy-in-place). The
  install path no longer restores from backup on a failed *download/extract*, which
  had needlessly rebuilt a healthy module.
- **Declared Dolibarr/PHP compatibility was wrong.** The descriptor claimed
  Dolibarr 14 while the code calls `isModEnabled()`/`DoliDB::prefix()` (v16) and
  `dolEncrypt()` (v17), fataling on older cores; `need_dolibarr_version` is now
  `17.0.0`. `phpmin` was over-declared as 8.0 while the code is PHP 7.4-clean;
  lowered to 7.4.
- **Migrations broke silently on MySQL (Oracle).** The 1.6.0/1.6.2/1.7.0 update
  scripts used MariaDB-only `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS` /
  `DROP FOREIGN KEY IF EXISTS`, which raise a syntax error on MySQL 5.7/8.x — the
  statement was skipped and activation still reported success, leaving columns
  missing. Rewritten in portable syntax (Dolibarr's `run_sql()` tolerates
  already-exists errors on replay), and `init()` now treats a `_load_tables()`
  result of `0` as a failure instead of silently passing.
- **Community-YAML setting re-enabled itself on every activation.** The
  1.6.0→1.6.1 migration unconditionally flipped `community_yaml_enabled` back on at
  each module activation. The flip is retired; the setting stays under user control.
- **Backup delete hardened.** `DMMBackup::delete()` now refuses to recursively delete
  a `backup_path` that does not resolve under the backups root, and `restore()`
  re-validates `module_id` before touching `custom/`.

### UX
- **Clearer failure messages.** When a restore or install fails because the web
  user cannot write inside `custom/`, the message now names the PHP user and the
  exact `chown`/`chmod` command to run (was a generic "Failed to stage backup copy").
- **Help & troubleshooting on the Dashboard.** A collapsible section explains the
  key concepts (sources, stable vs dev channel, token auto-matching, backups,
  self-hosted GitLab), common fixes (permissions, needs-token, GitHub rate limit),
  and links to the preflight diagnostics.
- **Consistent (?) tooltips** on the Update-channel selector and the Dashboard
  Source column. 16 new lang keys, French and English in sync.

## 1.10.3

### Fixed
- **Branch-HEAD fallback assumed main/master.** When a git-backed module exposes
  no release (and no branch is declared on its row), DMM tracked the HEAD of a
  guessed `main`/`master` branch. Self-hosted GitLab instances often default to a
  different branch — open-dsi defaults to a year-named branch like `2026` — so the
  guess 404'd. DMM now resolves the repo's real `default_branch` via the API
  (new `gitDefaultBranch()`), falling back to the guess only if that lookup fails.
  Applies to both the release fallback and the `dmm.json` manifest read.

## 1.10.2

### Fixed
- **GitLab install/update 404 on no-`v`-prefix tags.** DMM stores release
  versions with the leading `v` stripped and re-adds `v` at install time
  (`v14.0.103`). Self-hosted GitLab modules that tag without the prefix —
  e.g. open-dsi's banking4dolibarr tags `14.0.103` — returned
  "Gitlab download failed: HTTP 404". The GitLab archive download now tries the
  ref as-is and, on failure, retries with the `v` toggled, so both tagging
  conventions work.

## 1.10.1

### Fixed
- **Installed version not detected for `trim(file_get_contents(VERSION))` modules.**
  `getInstalledVersion()` only recognised a bare `file_get_contents(__DIR__...)`
  version assignment. Modules that wrap it in a call — e.g. open-dsi's
  `$this->version = trim(file_get_contents(__DIR__.'/../../VERSION'));`
  (banking4dolibarr) — were detected as installed but with a NULL version. The
  parser now tolerates an optional wrapper call (trim/rtrim/…). Run a *Check* on
  the module afterwards to repopulate the version.

## 1.10.0

### Added
- **Generic self-hosted GitLab support.** DMM can now follow and install
  modules from any GitLab instance (e.g. `git.open-dsi.fr`, `framagit.org`),
  not just a single hardcoded host. The "Add public repository" form accepts a
  full git URL (GitHub or GitLab, nested group namespaces supported) in addition
  to the `owner/repo` GitHub shortcut, and the dashboard source link points to
  the right instance. Public repositories need no token; the existing
  `PRIVATE-TOKEN` plumbing remains available for future per-host GitLab tokens.

### Fixed
- **PostgreSQL: empty catalog / no hub import.** `DMMClient::tableExists()` used
  the MySQL-only `SHOW TABLES LIKE`, which fails on PostgreSQL — DMM then stayed
  in non-standalone mode and `importFromHub()` bailed out with "requires
  standalone mode", leaving the registry empty. It now uses Dolibarr's portable
  `DDLListTables()` (with an `information_schema` fallback). Tested on MySQL/
  MariaDB and PostgreSQL.

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
