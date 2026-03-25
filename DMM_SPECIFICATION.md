# DoliModuleManager (DMM) — Specification

**Version:** 1.0.0-draft
**Date:** 2026-03-25
**License:** MIT OR LGPLv3 (TBD)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Goals & Non-Goals](#2-goals--non-goals)
3. [Prerequisites](#3-prerequisites)
4. [Architecture](#4-architecture)
5. [Manifest Format — `dmm.json`](#5-manifest-format--dmmjson)
6. [Release Block Format — `<!-- dmm -->`](#6-release-block-format----dmm---)
7. [Resolution Logic](#7-resolution-logic)
8. [GitHub API Integration](#8-github-api-integration)
9. [Database Schema](#9-database-schema)
10. [Caching Strategy](#10-caching-strategy)
11. [Installation & Update Mechanism](#11-installation--update-mechanism)
12. [Hybrid Architecture — Standalone vs Embedded](#12-hybrid-architecture--standalone-vs-embedded)
13. [Security Considerations](#13-security-considerations)
14. [Dolibarr Integration](#14-dolibarr-integration)
15. [Module Developer Guide](#15-module-developer-guide)

---

## 1. Overview

DoliModuleManager (DMM) is an open-source module manager for Dolibarr ERP. It provides a standardized mechanism for discovering, installing, and updating third-party Dolibarr modules directly from private or public GitHub repositories.

DMM uses the GitHub API as its distribution backend — no custom infrastructure required. Module developers tag releases on GitHub, and DMM handles the rest: version checking, compatibility verification, download, and installation.

DMM operates in two modes:

- **Standalone mode:** A full Dolibarr module (`dolimodulemanager`) providing a centralized admin UI to manage all compatible modules.
- **Embedded mode:** A lightweight PHP class (`DMMClient`) that any module can bundle to provide self-update capabilities, with or without the standalone module installed.

---

## 2. Goals & Non-Goals

### Goals

- Provide a frictionless install/update experience for end users (one-click from Dolibarr admin).
- Require zero custom infrastructure for module developers — GitHub is the only backend.
- Define a simple, open manifest format that any developer can adopt in minutes.
- Work in any PHP environment (Docker, VPS, shared hosting) with no system dependencies beyond `curl` and `json`.
- Support granular access control via GitHub tokens (per-client, revocable).
- Be fully open-source and vendor-neutral.

### Non-Goals

- DMM is not a marketplace. It does not handle payments, licensing fees, or commercial transactions.
- DMM does not enforce license compliance. Token access control is the developer's responsibility.
- DMM does not support non-GitHub backends in v1 (GitLab, Gitea may come later).
- DMM does not auto-update itself without user confirmation.

---

## 3. Prerequisites

### Runtime Environment

DMM runs inside Dolibarr, which means it executes in a **web server context** (Apache/mod_php, PHP-FPM behind Nginx, etc.) — not CLI. This has concrete implications:

- **No shell commands.** DMM cannot rely on `shell_exec`, `exec`, or `system`. These are frequently disabled in production PHP configs via `disable_functions`, and are unavailable in many Docker images. All operations (download, extraction, file manipulation) must use pure PHP.
- **No `git`.** Even when `exec` is available, `git` is not installed in most Dolibarr Docker images and should not be expected on shared hosting. DMM uses the GitHub API + tarball download instead.
- **Execution time limits.** `max_execution_time` is typically 30-60 seconds in web contexts. Tarball downloads and extractions for large modules must complete within this window, or DMM should handle timeouts gracefully.
- **Memory limits.** PHP's `memory_limit` (commonly 128-256 MB) applies. DMM must never load a full tarball into memory — all downloads stream directly to disk (see section 11).

### Required PHP Extensions

| Extension | Purpose | Bundled by default |
|---|---|---|
| `curl` | GitHub API calls, tarball download | Yes |
| `json` | API response parsing, manifest parsing | Yes (PHP 8.0+: always) |
| `Phar` | Tarball decompression and extraction (`PharData`) | Yes |
| `openssl` | HTTPS connections, `dolEncrypt`/`dolDecrypt` | Yes |
| `zlib` | `.tar.gz` decompression | Yes |

All five extensions are bundled and enabled by default in standard PHP builds. However, minimal or custom-compiled PHP installations may lack them. DMM checks for their presence at activation time and refuses to activate if any are missing.

Optional but recommended: `posix` (to detect PHP user for permission diagnostics), `mbstring` (for multibyte-safe string handling in changelogs).

### Filesystem Requirements

- **`/custom/` directory must be writable** by the PHP process (typically `www-data` on Debian/Ubuntu). DMM creates, deletes, and replaces directories within `/custom/`.
- **Temp directory must be writable.** DMM uses `sys_get_temp_dir()` for intermediate downloads and extractions. The PHP process must be able to create subdirectories and files there.
- **At least 50 MB free disk space** recommended. A single module tarball is typically 50 KB - 5 MB, but extraction creates temporary copies.

### Dolibarr Version

DMM requires **Dolibarr 14.0.0 or later**. This is the minimum version that provides:

- `dolEncrypt()` / `dolDecrypt()` functions for secure token storage (introduced in Dolibarr 13, stabilized in 14).
- The `/custom/` module directory convention.
- The hook system used by DMM lifecycle events.

### Preflight Diagnostic Tool

DMM ships with a preflight script (`dmm_preflight.php`) that verifies all prerequisites in a single run. It can be executed both from the command line and from a browser:

```bash
# CLI — full test
php dmm_preflight.php --dolibarr-root=/var/www/html

# CLI — with GitHub token test
php dmm_preflight.php --dolibarr-root=/var/www/html --token=ghp_xxxxx --repo=myorg/mymodule

# Browser — drop into /custom/ or any web-accessible directory and open in browser
https://my-dolibarr.example.com/custom/dmm_preflight.php

# JSON output for automation
php dmm_preflight.php --json
```

The script checks: PHP version and extensions, Dolibarr detection and version, `dolEncrypt` availability, `/custom/` permissions and disk space, temp directory access, `PharData` create/decompress/extract cycle, GitHub API connectivity (unauthenticated and authenticated), and a full integration test (download + extract a real tarball from GitHub).

The preflight tool auto-detects its execution context (CLI vs web) and adapts its output format accordingly — plain text in a terminal, `<pre>`-wrapped text in a browser.

---

## 4. Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   Dolibarr Instance                     │
│                                                         │
│  ┌──────────────────────┐   ┌────────────────────────┐  │
│  │  DoliModuleManager   │   │   Third-Party Module   │  │
│  │    (standalone)      │   │                        │  │
│  │                      │   │  ┌──────────────────┐  │  │
│  │  - Module catalog    │   │  │  DMMClient.class │  │  │
│  │  - Install / Update  │◄─►│  │  (embedded)      │  │  │
│  │  - Token management  │   │  └──────────────────┘  │  │
│  │  - Cache layer       │   │                        │  │
│  └──────────┬───────────┘   └────────────┬───────────┘  │
│             │                            │              │
└─────────────┼────────────────────────────┼──────────────┘
              │         GitHub API         │
              │    (authenticated via      │
              │     Fine-Grained Token)    │
              ▼                            ▼
┌─────────────────────────────────────────────────────────┐
│                     GitHub                              │
│                                                         │
│  ┌─────────────────┐  ┌─────────────────┐               │
│  │  Private Repo A  │  │  Private Repo B │  ...         │
│  │                  │  │                 │               │
│  │  dmm.json        │  │  dmm.json       │              │
│  │  Releases + tags │  │  Releases + tags│              │
│  │  Source tarball   │  │  Source tarball │              │
│  └─────────────────┘  └─────────────────┘               │
└─────────────────────────────────────────────────────────┘
```

### Key Components

| Component | Role |
|---|---|
| `DoliModuleManager` module | Standalone Dolibarr module. Admin UI, catalog, token storage, shared cache. |
| `DMMClient` class | Lightweight PHP class (~200 lines). Can be embedded in any module for self-update. |
| `dmm.json` | Manifest file at the root of a module's GitHub repo. Declares metadata and compatibility matrix. |
| `<!-- dmm -->` block | Inline metadata in GitHub Release body. Per-release compatibility override. |
| GitHub API | Distribution backend. Provides releases, tarballs, and access control. |

---

## 5. Manifest Format — `dmm.json`

The `dmm.json` file lives at the root of a module's GitHub repository. It is the primary source of truth for module metadata and compatibility information.

### Schema

```json
{
  "schema_version": "1",
  "module_id": "<string>",
  "name": "<string>",
  "description": "<string>",
  "author": "<string>",
  "url": "<string|null>",
  "license": "<string>",
  "dolibarr_module_id": "<int|null>",
  "compatibility": {
    "<semver>": {
      "dolibarr_min": "<version>",
      "dolibarr_max": "<version_wildcard>",
      "php_min": "<version>",
      "php_max": "<version_wildcard|null>"
    }
  },
  "dependencies": {
    "modules": ["<module_id>"],
    "php_extensions": ["<ext_name>"]
  }
}
```

### Field Reference

| Field | Type | Required | Description |
|---|---|---|---|
| `schema_version` | string | **yes** | Always `"1"` for this spec version. Allows future evolution without breaking existing manifests. |
| `module_id` | string | **yes** | Unique module identifier. Must match the Dolibarr module directory name in `/custom/`. Lowercase, alphanumeric, underscores allowed. Example: `"factufournisseur"`. |
| `name` | string | **yes** | Human-readable module name. Example: `"Supplier Invoice Generator"`. |
| `description` | string | **yes** | Short description (one or two sentences). |
| `author` | string | **yes** | Author or organization name. |
| `url` | string | no | URL to module homepage, documentation, or DoliStore page. |
| `license` | string | **yes** | SPDX license identifier. Example: `"GPL-3.0-or-later"`. |
| `dolibarr_module_id` | int | no | The numeric module ID used in `modMyModule.class.php` (`$this->numero`). Useful for DMM to cross-reference with the installed module descriptor. |
| `compatibility` | object | **yes** | Version-keyed compatibility matrix. See below. |
| `dependencies.modules` | array | no | List of `module_id` values that must be installed. DMM will warn (not block) if missing. |
| `dependencies.php_extensions` | array | no | Required PHP extensions. DMM checks `extension_loaded()` before install. |

### Compatibility Object

Keys are **module version strings** (semver). Values declare the environment constraints for that version.

| Field | Type | Required | Description |
|---|---|---|---|
| `dolibarr_min` | string | **yes** | Minimum Dolibarr version (inclusive). Example: `"16.0.0"`. |
| `dolibarr_max` | string | **yes** | Maximum Dolibarr version (inclusive). Supports wildcards: `"20.*"` means any 20.x release. |
| `php_min` | string | **yes** | Minimum PHP version (inclusive). Example: `"8.0"`. |
| `php_max` | string | no | Maximum PHP version. Defaults to `"*"` (any). |

Version keys in the compatibility object can use the following patterns:

- Exact: `"1.3.0"` — applies to this release only.
- Minor wildcard: `"1.2.x"` — applies to all 1.2.* releases not explicitly listed.
- Major wildcard: `"1.x"` — applies to all 1.* releases not explicitly listed.

DMM resolves from most specific to least specific.

### Full Example

```json
{
  "schema_version": "1",
  "module_id": "factufournisseur",
  "name": "Supplier Invoice Generator",
  "description": "Automated supplier invoice creation with batch processing and VAT management.",
  "author": "Anatole Consulting",
  "url": "https://anatole-consulting.fr/modules/factufournisseur",
  "license": "GPL-3.0-or-later",
  "dolibarr_module_id": 500100,
  "compatibility": {
    "2.0.0": {
      "dolibarr_min": "20.0.0",
      "dolibarr_max": "22.*",
      "php_min": "8.2"
    },
    "1.3.0": {
      "dolibarr_min": "16.0.0",
      "dolibarr_max": "20.*",
      "php_min": "8.0"
    },
    "1.x": {
      "dolibarr_min": "14.0.0",
      "dolibarr_max": "19.*",
      "php_min": "7.4"
    }
  },
  "dependencies": {
    "modules": [],
    "php_extensions": ["curl", "json", "zip"]
  }
}
```

---

## 6. Release Block Format — `<!-- dmm -->`

Module developers can embed compatibility metadata directly in the body of a GitHub Release. This serves as a lightweight alternative (or complement) to `dmm.json`.

### Format

The block must be an HTML comment in the release body, using YAML-like `key: value` syntax:

```markdown
## 1.3.0 - 2026-03-25

### Added
- Date filter on invoice list
- CSV export

### Fixed
- VAT bug on non-liable third parties

<!-- dmm
dolibarr_min: 16.0.0
dolibarr_max: 20.*
php_min: 8.0
-->
```

### Supported Fields

| Field | Required | Description |
|---|---|---|
| `dolibarr_min` | **yes** | Minimum Dolibarr version. |
| `dolibarr_max` | **yes** | Maximum Dolibarr version (wildcards supported). |
| `php_min` | **yes** | Minimum PHP version. |
| `php_max` | no | Maximum PHP version. |
| `module_id` | no | Overrides the repository-level `module_id`. Useful for monorepos (not recommended but supported). |

### Parsing Rules

- The block is extracted via regex: `/<!--\s*dmm\s*\n([\s\S]*?)-->/`
- Each line inside the block is parsed as `key: value` (trimmed).
- Lines starting with `#` are treated as comments and ignored.
- Unknown keys are silently ignored (forward compatibility).

### Behavior

- If both `dmm.json` and a release block exist for the same version, the **release block takes precedence** for that specific release. This allows per-release overrides (e.g., a hotfix backported to an older Dolibarr range).
- If only the release block exists (no `dmm.json`), DMM uses it as the sole source.
- If neither exists, DMM assumes compatibility with all Dolibarr/PHP versions (permissive fallback, with a warning in the admin UI).

---

## 7. Resolution Logic

When DMM checks for updates, it determines the **best available version** for the client's environment.

### Inputs

| Parameter | Source |
|---|---|
| Installed module version | Parsed from `modMyModule.class.php` → `$this->version` |
| Dolibarr version | `DOL_VERSION` constant or `$conf->global->MAIN_VERSION_LAST_INSTALL` |
| PHP version | `PHP_VERSION` constant |
| Available releases | GitHub Releases API |
| Compatibility data | `dmm.json` + release blocks |

### Algorithm

```
1. Fetch all GitHub releases (non-draft, non-prerelease).
2. For each release, determine compatibility:
   a. If a <!-- dmm --> block exists in the release body → use it.
   b. Else if dmm.json exists → find the best matching entry:
      - Exact version match first ("1.3.0")
      - Then minor wildcard ("1.3.x")
      - Then major wildcard ("1.x")
   c. Else → assume compatible (flag as "unverified").
3. Filter releases: keep only those where:
   - dolibarr_min <= current Dolibarr version <= dolibarr_max
   - php_min <= current PHP version <= php_max
4. Sort remaining releases by semver descending.
5. The first result is the "best available version".
6. If best_available > installed_version → update available.
```

### Edge Cases

- **Downgrade protection:** DMM never proposes a version lower than the installed one.
- **Pre-releases:** Ignored by default. A future setting may allow opting into pre-releases.
- **No compatible version found:** DMM displays a message explaining why (e.g., "Latest version requires Dolibarr 20+, you are on 17.0.2").
- **Unverified compatibility:** If no `dmm.json` or release block is found, DMM marks the update as "unverified" and lets the user decide.

---

## 8. GitHub API Integration

DMM interacts exclusively with the GitHub REST API v3.

### Authentication

All requests use a **Fine-Grained Personal Access Token** (recommended) or a classic PAT, sent as a Bearer token:

```
Authorization: Bearer ghp_xxxxxxxxxxxx
```

**Recommended token permissions (Fine-Grained):**

- Repository access: Only select repositories (the modules the client has purchased).
- Permissions: `Contents: Read` (sufficient for releases, file contents, and tarballs).

### Endpoints Used

| Purpose | Endpoint | Method |
|---|---|---|
| Fetch manifest | `/repos/{owner}/{repo}/contents/dmm.json` | GET |
| List releases | `/repos/{owner}/{repo}/releases` | GET |
| Get latest release | `/repos/{owner}/{repo}/releases/latest` | GET |
| Download tarball | `/repos/{owner}/{repo}/tarball/{tag}` | GET |
| List repos accessible by token | `/installation/repositories` or `/user/repos` | GET |

### Rate Limits

- Authenticated requests: **5,000/hour** per token.
- DMM uses caching (see section 10) to minimize API calls.
- Typical usage: 2-3 calls per module per day (manifest + releases + optional tarball).
- With 50 modules and daily checks: ~150 calls/day — well within limits.

### Response Handling

- **401 Unauthorized:** Token is invalid or revoked. DMM surfaces a clear error: "Your access token is invalid. Please update it in DMM settings."
- **403 Forbidden:** Token doesn't have access to this repo. DMM hides the module from the catalog.
- **404 Not Found:** Repo doesn't exist or token lacks access. Same behavior as 403.
- **422 / 5xx:** Transient errors. DMM retries once, then caches the failure and reports it.

---

## 9. Database Schema

DMM stores all its data in dedicated tables, created during module activation. This avoids polluting Dolibarr's `llx_const` table and provides proper relational structure.

### Tables

#### `llx_dmm_token`

Stores GitHub access tokens. Supports multiple tokens for different vendors/organizations.

```sql
CREATE TABLE llx_dmm_token (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(255) NOT NULL,          -- Human-readable label ("Anatole Consulting", "Client X")
    token           TEXT NOT NULL,                   -- Encrypted GitHub token (dolEncrypt)
    github_owner    VARCHAR(255) DEFAULT NULL,       -- Default GitHub org/user for this token (e.g., "anatole-consulting")
    token_type      VARCHAR(20) DEFAULT 'pat',       -- "pat" (classic) or "fine_grained"
    status          TINYINT DEFAULT 1,               -- 1 = active, 0 = disabled
    last_validated  DATETIME DEFAULT NULL,           -- Last successful API call with this token
    note            TEXT DEFAULT NULL,               -- Admin notes
    date_creation   DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat   INTEGER NOT NULL,
    fk_user_modif   INTEGER DEFAULT NULL
) ENGINE=InnoDB;
```

#### `llx_dmm_module`

Tracks all DMM-aware modules (installed or available). Acts as both a registry and a cache.

```sql
CREATE TABLE llx_dmm_module (
    rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
    module_id               VARCHAR(128) NOT NULL,          -- Unique module identifier (= directory name in /custom/)
    name                    VARCHAR(255) DEFAULT NULL,       -- Human-readable name (from dmm.json)
    description             TEXT DEFAULT NULL,               -- Short description (from dmm.json)
    author                  VARCHAR(255) DEFAULT NULL,       -- Author (from dmm.json)
    license                 VARCHAR(50) DEFAULT NULL,        -- SPDX identifier
    url                     VARCHAR(500) DEFAULT NULL,       -- Module homepage
    github_repo             VARCHAR(255) NOT NULL,           -- Full repo path: "owner/repo"
    fk_dmm_token            INTEGER NOT NULL,                -- FK → llx_dmm_token.rowid
    installed_version       VARCHAR(20) DEFAULT NULL,        -- Currently installed version (NULL if not installed)
    installed               TINYINT DEFAULT 0,               -- 1 = installed locally, 0 = available only
    -- Cache fields
    cache_latest_version    VARCHAR(20) DEFAULT NULL,        -- Latest release version on GitHub
    cache_latest_compatible VARCHAR(20) DEFAULT NULL,        -- Latest version compatible with this Dolibarr/PHP
    cache_changelog         TEXT DEFAULT NULL,               -- Changelog of latest compatible version (max 2000 chars)
    cache_manifest_json     TEXT DEFAULT NULL,               -- Full dmm.json content (cached)
    cache_etag              VARCHAR(128) DEFAULT NULL,       -- GitHub API ETag for conditional requests
    cache_last_check        DATETIME DEFAULT NULL,           -- Last successful API check
    cache_last_error        VARCHAR(500) DEFAULT NULL,       -- Last error message (NULL if no error)
    date_creation           DATETIME NOT NULL,
    tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dmm_module (module_id, github_repo),
    KEY idx_dmm_module_token (fk_dmm_token),
    CONSTRAINT fk_dmm_module_token FOREIGN KEY (fk_dmm_token) REFERENCES llx_dmm_token(rowid)
) ENGINE=InnoDB;
```

#### `llx_dmm_backup`

Tracks module backups created before updates, enabling rollback.

```sql
CREATE TABLE llx_dmm_backup (
    rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
    fk_dmm_module   INTEGER NOT NULL,                   -- FK → llx_dmm_module.rowid
    module_id       VARCHAR(128) NOT NULL,               -- Denormalized for quick access
    version_from    VARCHAR(20) NOT NULL,                -- Version before update
    version_to      VARCHAR(20) NOT NULL,                -- Version after update
    backup_path     VARCHAR(500) NOT NULL,               -- Full path to backup directory
    backup_size     BIGINT DEFAULT NULL,                 -- Size in bytes
    status          VARCHAR(20) DEFAULT 'ok',            -- "ok", "restored", "deleted"
    date_creation   DATETIME NOT NULL,
    CONSTRAINT fk_dmm_backup_module FOREIGN KEY (fk_dmm_module) REFERENCES llx_dmm_module(rowid)
) ENGINE=InnoDB;
```

#### `llx_dmm_setting`

General DMM configuration (replaces the need for `llx_const`).

```sql
CREATE TABLE llx_dmm_setting (
    rowid   INTEGER AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(128) NOT NULL UNIQUE,    -- Setting key
    value   TEXT DEFAULT NULL                -- Setting value
) ENGINE=InnoDB;
```

Default settings inserted on module activation:

| name | default | description |
|---|---|---|
| `check_interval` | `86400` | Seconds between automatic checks. |
| `backup_retention_days` | `30` | Days to keep module backups. |
| `backup_retention_count` | `5` | Max backup versions per module. |
| `notify_email` | (empty) | Email for update notifications. |
| `temp_dir` | (empty) | Custom temp dir. Falls back to Dolibarr sys temp. |

### Entity-Relationship Summary

```
llx_dmm_token (1) ──── (N) llx_dmm_module (1) ──── (N) llx_dmm_backup
                                                    
llx_dmm_setting (standalone key-value, no FK)
```

---

## 10. Caching Strategy

DMM caches API responses to minimize GitHub API calls and improve admin page load times.

### Storage

Cache data is stored directly in the `llx_dmm_module` table, in the `cache_*` columns. Each module row holds its own cache — no separate cache table, no key-value sprawl.

### Check Frequency

- **Default:** Once every 24 hours per module (configurable in `llx_dmm_setting`).
- **Configurable:** Admin can set the `check_interval` setting (1h, 6h, 12h, 24h, 7d).
- **Manual override:** "Check now" button in admin UI bypasses cache.
- **Conditional requests:** DMM sends `If-None-Match` with `cache_etag`. GitHub returns `304 Not Modified` if nothing changed — this does **not** count against rate limits.

### Cache Invalidation

- Cache is invalidated (all `cache_*` columns reset to NULL in `llx_dmm_module`) when:
  - The check interval has elapsed.
  - The user clicks "Check now".
  - The GitHub token is changed.
  - The module is installed or updated (resets that module's cache row).

---

## 11. Installation & Update Mechanism

### Pre-flight Checks

Before any install or update, DMM performs:

1. **Compatibility check:** Dolibarr version, PHP version, required PHP extensions.
2. **Disk space check:** Ensure sufficient space in `/custom/` (warns if < 50MB free).
3. **Write permission check:** Verify PHP process can write to the target directory.
4. **Dependency check:** Warn if required modules (from `dependencies.modules`) are not installed.

### Web Context Constraints

DMM runs inside Dolibarr's web server process, not as a CLI script. This constrains the implementation:

- **No `exec`/`shell_exec`.** All operations use pure PHP functions. No calls to `git`, `tar`, `unzip`, or any external binary.
- **Execution time.** Downloads and extractions must complete within `max_execution_time` (typically 30-60s). For large modules, DMM extends the limit with `set_time_limit()` if the PHP configuration allows it.
- **Memory.** Tarballs are **never loaded into PHP memory**. A 150 MB module loaded via `curl_exec` with `CURLOPT_RETURNTRANSFER` would exceed most `memory_limit` settings and crash. All downloads stream to disk.

### Download

1. DMM calls `GET /repos/{owner}/{repo}/tarball/{tag}` with the token.
2. GitHub returns a `.tar.gz` stream (302 redirect to a CDN URL with a temporary signed token).
3. DMM streams the archive **directly to a file** on disk using `CURLOPT_FILE` — never `CURLOPT_RETURNTRANSFER`:

```php
$fp = fopen($tarGzPath, 'wb');
$ch = curl_init($tarballUrl);
curl_setopt($ch, CURLOPT_FILE, $fp);           // stream to file, not memory
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // follow GitHub's 302 redirect
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: DMM/1.0',
    'Authorization: Bearer ' . $token,
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);
```

This approach has zero memory overhead regardless of module size.

### Extraction

DMM uses PHP's native `PharData` class — no system dependencies required:

```php
$tarGzPath = '/tmp/dmm/module.tar.gz';
$tarPath   = '/tmp/dmm/module.tar';

// Decompress .tar.gz → .tar
$phar = new PharData($tarGzPath);
$phar->decompress();

// CRITICAL: unset the PharData object before opening the .tar.
// PHP's internal Phar registry keeps a reference and will throw
// "a phar with that name already exists" otherwise.
unset($phar);

// Extract .tar → directory
$tar = new PharData($tarPath);
$tar->extractTo('/tmp/dmm/extracted/');
unset($tar);
```

GitHub tarballs contain a top-level directory named `{owner}-{repo}-{hash}/`. DMM detects this wrapper and moves the contents to the correct target.

### Installation (New Module)

1. Extract to `/tmp/dmm/extracted/`.
2. Identify the module directory (must match `module_id` from manifest, or the repo name as fallback).
3. Move the directory to `{dolibarr_root}/custom/{module_id}/`.
4. Report success. The user then activates the module from Dolibarr's standard module admin page.

DMM does **not** auto-activate modules. Activation is a deliberate user action.

### Update (Existing Module)

1. **Backup:** Copy the current module directory to `{dolibarr_root}/custom/_dmm_backups/{module_id}_{old_version}_{timestamp}/`. Record the backup in `llx_dmm_backup`.
2. **Extract** new version to temp directory.
3. **Replace:** Remove old module directory and move new one in place.
4. **Verify:** Check that `modMyModule.class.php` is present and parseable.
5. **Update registry:** Set `installed_version` in `llx_dmm_module` to the new version. Reset cache.
6. **Report:** Show changelog and new version number. If activation triggers a database migration (via Dolibarr's module activation hooks), DMM advises the user to re-activate the module.

### Rollback

If the update fails (missing descriptor, extraction error, write failure):

1. Remove the partially extracted directory.
2. Restore from backup (lookup path in `llx_dmm_backup`). Mark backup as `status = 'restored'`.
3. Report the error with details.

Backups are kept for a configurable period (default: 30 days, last 5 versions per module — see `llx_dmm_setting`).

---

## 12. Hybrid Architecture — Standalone vs Embedded

### Standalone: DoliModuleManager Module

A full Dolibarr module installed in `/custom/dolimodulemanager/`.

**Features:**

- Centralized admin page listing all DMM-compatible modules.
- Token management (multiple tokens for different vendors/GitHub orgs).
- Catalog view: installed modules, available updates, and installable modules.
- Shared cache: one check serves all modules.
- Batch operations: "Update all" button.
- Backup management: view and restore previous versions.

**Admin UI Pages:**

| Page | Path | Purpose |
|---|---|---|
| Dashboard | `/dolimodulemanager/admin/index.php` | Overview: modules with updates, last check time. |
| Catalog | `/dolimodulemanager/admin/catalog.php` | All accessible modules (installed + available). |
| Settings | `/dolimodulemanager/admin/setup.php` | Token management, check interval, backup retention. |
| Repositories | `/dolimodulemanager/admin/repositories.php` | Manage GitHub orgs/repos to watch. |
| Backups | `/dolimodulemanager/admin/backups.php` | View and restore module backups. |
| Module Detail | `/dolimodulemanager/admin/module.php?id=xxx` | Per-module: version history, changelog, compatibility, install/update. |

### Embedded: DMMClient Class

A single PHP file (`DMMClient.class.php`, ~200-300 lines) that any module can bundle.

**Capabilities:**

- Check for updates for the host module only.
- Download and install updates.
- Minimal UI: an update banner or a tab on the module's own admin page.

**Integration pattern:**

```php
// In the module's admin page (e.g., /monmodule/admin/about.php)

// Option 1: Delegate to standalone DMM if available
if (dol_include_once('/dolimodulemanager/class/dmmclient.class.php')) {
    $dmm = new DMMClient();
    $update_info = $dmm->checkUpdate('monmodule');
} else {
    // Option 2: Use embedded DMMClient
    dol_include_once('/monmodule/class/DMMClient.class.php');
    $dmm = new DMMClient();
    $update_info = $dmm->checkUpdate('monmodule');
}

if ($update_info && $update_info['update_available']) {
    print '<div class="info">';
    print 'Version '.$update_info['latest_version'].' is available. ';
    print '<a href="'.$update_info['update_url'].'">Update now</a>';
    print '</div>';
}
```

**Priority logic:**

1. If standalone DMM is installed → the embedded client delegates to it (shared cache, shared tokens).
2. If standalone DMM is not installed → the embedded client works autonomously (its own token stored in `llx_const` under the module's namespace, since the DMM tables are not available).

### DMMClient Public API

```php
class DMMClient
{
    /**
     * Check if an update is available for a module.
     *
     * @param  string      $module_id   Module identifier (directory name in /custom/).
     * @param  string|null $token       GitHub token. If null, reads from llx_dmm_token (standalone) or llx_const (embedded fallback).
     * @param  string|null $repo        GitHub repo (owner/repo). If null, reads from module config.
     * @return array|null  Update info or null if no update / error.
     *
     * Return format:
     * [
     *     'update_available' => bool,
     *     'installed_version' => '1.2.0',
     *     'latest_version' => '1.3.0',
     *     'latest_compatible_version' => '1.3.0',
     *     'changelog' => '### Added\n- ...',
     *     'download_tag' => 'v1.3.0',
     *     'compatibility' => ['dolibarr_min' => '16.0.0', ...],
     *     'update_url' => '/dolimodulemanager/admin/module.php?action=update&id=monmodule',
     *     'verified' => true,           // false if no dmm.json / release block found
     *     'checked_at' => '2026-03-25T08:30:00Z',
     * ]
     */
    public function checkUpdate(string $module_id, ?string $token = null, ?string $repo = null): ?array

    /**
     * Download and install/update a module.
     *
     * @param  string $module_id  Module identifier.
     * @param  string $tag        Git tag to install (e.g., 'v1.3.0').
     * @param  string|null $token GitHub token.
     * @param  string|null $repo  GitHub repo (owner/repo).
     * @return array  Result: ['success' => bool, 'message' => string, 'backup_path' => string|null]
     */
    public function installOrUpdate(string $module_id, string $tag, ?string $token = null, ?string $repo = null): array

    /**
     * Restore a module from a backup.
     *
     * @param  string $module_id   Module identifier.
     * @param  string $backup_path Path to backup directory.
     * @return array  Result: ['success' => bool, 'message' => string]
     */
    public function rollback(string $module_id, string $backup_path): array

    /**
     * List all modules accessible via the given token.
     * Scans repos for dmm.json presence.
     *
     * @param  string|null $token GitHub token.
     * @return array  List of module metadata.
     */
    public function listAvailableModules(?string $token = null): array

    /**
     * Parse the dmm.json manifest from a repository.
     *
     * @param  string $owner Repo owner.
     * @param  string $repo  Repo name.
     * @param  string $token GitHub token.
     * @return array|null Parsed manifest or null if not found.
     */
    public function fetchManifest(string $owner, string $repo, string $token): ?array

    /**
     * Parse <!-- dmm --> block from a release body.
     *
     * @param  string $release_body Markdown body of a GitHub release.
     * @return array|null Parsed compatibility data or null.
     */
    public function parseReleaseBlock(string $release_body): ?array
}
```

---

## 13. Security Considerations

### Token Storage

- Tokens are stored in the `llx_dmm_token` table, encrypted using Dolibarr's native `dolEncrypt()` / `dolDecrypt()` functions (available since Dolibarr 13).
- Each token has a label, optional GitHub owner scope, and an active/disabled status.
- Tokens are never displayed in full in the admin UI (masked: `ghp_xxxx...xxxx`).
- Tokens are never logged or included in error messages.
- Revoking access: set `status = 0` to disable a token locally, or delete the row entirely. The GitHub token itself should also be revoked on github.com.

### Token Scope

- Module developers should instruct clients to create **Fine-Grained Tokens** with minimal permissions:
  - Repository access: only purchased module repos.
  - Permission: `Contents: Read-only`.
- DMM displays a warning if a token has broader permissions than necessary (detectable via the GitHub API's token introspection).

### Download Integrity

- GitHub tarballs are served over HTTPS with temporary signed URLs.
- Future enhancement: support for a `checksum` field in `dmm.json` or release block (SHA-256 of the tarball per version).

### Filesystem Safety

- DMM never writes outside of `{dolibarr_root}/custom/` and the configured temp/backup directories.
- Directory traversal protection: `module_id` is sanitized (alphanumeric + underscore only).
- DMM refuses to overwrite core Dolibarr directories (whitelist check against known core module names).

### User Confirmation

- All install/update actions require explicit user confirmation (no silent auto-updates).
- The confirmation dialog shows: version change, changelog summary, and compatibility status.

---

## 14. Dolibarr Integration

### Module Descriptor

The standalone DMM module follows standard Dolibarr module conventions:

```php
class modDoliModuleManager extends DolibarrModules
{
    public function __construct($db)
    {
        $this->numero = 777100;  // TBD - must be unique
        $this->rights_class = 'dolimodulemanager';
        $this->family = 'technic';
        $this->module_position = 500;
        $this->name = 'DoliModuleManager';
        $this->description = 'Module manager with GitHub integration';
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_DOLIMODULEMANAGER';
        $this->need_dolibarr_version = array(14, 0, 0);
        // ...
    }
}
```

### Permissions

| Permission | Code | Description |
|---|---|---|
| Read | `dolimodulemanager->read` | View module catalog, check for updates. |
| Write | `dolimodulemanager->write` | Install and update modules, manage tokens. |
| Admin | `dolimodulemanager->admin` | Change DMM settings, manage backups, delete tokens. |

Only users with the `write` or `admin` permission should be able to trigger installs/updates.

### Scheduled Task (Cron)

DMM registers a Dolibarr cron job for automated daily checks:

- **Class:** `DMMCron`
- **Method:** `checkAllUpdates()`
- **Frequency:** Configurable (default: once daily at 03:00).
- **Behavior:** Checks all registered modules, updates cache, and optionally sends an email notification to admins if updates are available.

### Hooks

DMM fires Dolibarr hooks at key lifecycle events, allowing other modules to react:

| Hook | Context | Triggered When |
|---|---|---|
| `dmmBeforeInstall` | `module_id`, `version`, `source_path` | Before a module is installed. |
| `dmmAfterInstall` | `module_id`, `version`, `install_path` | After successful installation. |
| `dmmBeforeUpdate` | `module_id`, `old_version`, `new_version` | Before a module is updated. |
| `dmmAfterUpdate` | `module_id`, `old_version`, `new_version` | After successful update. |
| `dmmUpdateFailed` | `module_id`, `error` | After a failed update (before rollback). |
| `dmmAfterRollback` | `module_id`, `restored_version` | After a successful rollback. |

### Detecting Installed Modules

DMM scans `/custom/` directories for:

1. A `dmm.json` file (preferred) → module is DMM-aware.
2. A `DMMClient.class.php` file → module has embedded DMM support.
3. A standard `core/modules/mod*.class.php` descriptor → DMM reads `$this->version` and other metadata to display non-DMM modules in a "not managed" list.

For DMM-aware modules, the `dmm.json` also stores the GitHub repository coordinates (`module_id` maps to a repo via a local config or a convention like `{org}/{module_id}`).

### Repository Configuration

Each module's GitHub repo is configured either:

- **In `dmm.json`** via a `repository` field (optional, for standalone modules):
  ```json
  {
    "repository": "anatole-consulting/factufournisseur"
  }
  ```
- **In DMM's settings** (admin page): a mapping of `module_id → owner/repo`.
- **By convention:** DMM can try `{token_owner}/{module_id}` as a default.

---

## 15. Module Developer Guide

### Minimum Setup (5 Minutes)

To make your Dolibarr module compatible with DMM, you need exactly one thing: a `<!-- dmm -->` block in your GitHub Release body.

**Step 1:** Create a release on GitHub with a semver tag (e.g., `v1.3.0`).

**Step 2:** In the release body, add:

```markdown
## What's new

- Your changelog here

<!-- dmm
dolibarr_min: 16.0.0
dolibarr_max: 20.*
php_min: 8.0
-->
```

That's it. Your module is now DMM-compatible.

### Recommended Setup (15 Minutes)

**Step 1:** Add a `dmm.json` at the root of your repository (copy and adapt the example from section 5).

**Step 2:** Continue adding `<!-- dmm -->` blocks to your releases for per-version overrides.

**Step 3 (optional):** Bundle the `DMMClient.class.php` file in your module for embedded self-update support. Copy it to `/yourmodule/class/DMMClient.class.php` and add the integration snippet from section 12 to your admin page.

### GitHub Actions Automation

A sample workflow to automate release creation:

```yaml
# .github/workflows/release.yml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Extract version from tag
        id: version
        run: echo "VERSION=${GITHUB_REF_NAME#v}" >> $GITHUB_OUTPUT

      - name: Read changelog for this version
        id: changelog
        run: |
          # Extract the section for this version from CHANGELOG.md
          # (assumes Keep a Changelog format)
          awk '/^## \[${{ steps.version.outputs.VERSION }}\]/{found=1; next} /^## \[/{found=0} found' CHANGELOG.md > release_notes.md

      - name: Read compatibility from dmm.json
        id: compat
        run: |
          COMPAT=$(jq -r '.compatibility["${{ steps.version.outputs.VERSION }}"]' dmm.json)
          DOLI_MIN=$(echo $COMPAT | jq -r '.dolibarr_min')
          DOLI_MAX=$(echo $COMPAT | jq -r '.dolibarr_max')
          PHP_MIN=$(echo $COMPAT | jq -r '.php_min')
          echo "DOLI_MIN=$DOLI_MIN" >> $GITHUB_OUTPUT
          echo "DOLI_MAX=$DOLI_MAX" >> $GITHUB_OUTPUT
          echo "PHP_MIN=$PHP_MIN" >> $GITHUB_OUTPUT

      - name: Append DMM block to release notes
        run: |
          echo "" >> release_notes.md
          echo "<!-- dmm" >> release_notes.md
          echo "dolibarr_min: ${{ steps.compat.outputs.DOLI_MIN }}" >> release_notes.md
          echo "dolibarr_max: ${{ steps.compat.outputs.DOLI_MAX }}" >> release_notes.md
          echo "php_min: ${{ steps.compat.outputs.PHP_MIN }}" >> release_notes.md
          echo "-->" >> release_notes.md

      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          body_path: release_notes.md
          draft: false
          prerelease: false
```

### Verifying End-User Environment

Before a client installs any DMM-compatible module, they can run the preflight diagnostic tool to check their environment:

```bash
# Drop dmm_preflight.php into the Dolibarr server, then:
php dmm_preflight.php --dolibarr-root=/var/www/html

# Or open it in a browser:
# https://my-dolibarr.example.com/custom/dmm_preflight.php
```

The preflight tool checks all prerequisites documented in section 3. Include `dmm_preflight.php` in your module's distribution or link to the DMM repository in your installation instructions.

### Validating Your `dmm.json`

DMM will provide a JSON schema file (`dmm-schema.json`) that module developers can use to validate their manifest:

```bash
# Validate with any JSON schema tool
npx ajv validate -s dmm-schema.json -d dmm.json
```

---

## Appendix A — Glossary

| Term | Definition |
|---|---|
| **DMM** | DoliModuleManager. |
| **Module** | A Dolibarr third-party extension, installed in `/custom/`. |
| **Manifest** | The `dmm.json` file at the root of a module's GitHub repository. |
| **Release block** | The `<!-- dmm ... -->` HTML comment in a GitHub Release body. |
| **Token** | A GitHub Personal Access Token (Fine-Grained or Classic) used for API authentication. |
| **Standalone mode** | DMM installed as a full Dolibarr module with its own admin UI. |
| **Embedded mode** | The `DMMClient` class bundled inside a third-party module for autonomous self-update. |

## Appendix B — Database Tables Summary

| Table | Purpose | Key Fields |
|---|---|---|
| `llx_dmm_token` | GitHub access tokens (encrypted) | `label`, `token`, `github_owner`, `status` |
| `llx_dmm_module` | Module registry + cache | `module_id`, `github_repo`, `installed_version`, `cache_*` |
| `llx_dmm_backup` | Backup history for rollback | `module_id`, `version_from`, `version_to`, `backup_path` |
| `llx_dmm_setting` | DMM configuration | `name`, `value` |

See [Section 9 — Database Schema](#9-database-schema) for full DDL.

## Appendix C — Known Caveats

This appendix documents technical pitfalls discovered during development. Future contributors should read this before modifying core DMM logic.

### PharData Registry Collision

**Symptom:** `Unable to add newly converted phar "/tmp/.../module.tar" to the list of phars, a phar with that name already exists`

**Cause:** PHP maintains an internal registry of all Phar/PharData objects opened during a process lifecycle. When `PharData::decompress()` creates a `.tar` from a `.tar.gz`, the resulting file path is registered. If any earlier `PharData` instance was opened on the same `.tar` path (e.g., during a build step), the registry blocks re-use — even after `unset()`.

**Solution:** Always `unset()` the `PharData` object immediately after use, before opening another archive at a path that might collide. If building and then decompressing (e.g., in tests), use different filenames for the build and the final archive:

```php
// WRONG — will crash
$tar = new PharData('/tmp/module.tar');
$tar->buildFromDirectory($sourceDir);
$tar->compress(Phar::GZ);
unset($tar);
$gz = new PharData('/tmp/module.tar.gz');
$gz->decompress(); // → tries to create /tmp/module.tar → BOOM

// CORRECT — different filenames
$tar = new PharData('/tmp/build.tar');
$tar->buildFromDirectory($sourceDir);
$tar->compress(Phar::GZ);
unset($tar);
rename('/tmp/build.tar.gz', '/tmp/module.tar.gz');
unlink('/tmp/build.tar');
$gz = new PharData('/tmp/module.tar.gz');
$gz->decompress(); // → creates /tmp/module.tar → OK
unset($gz);
```

In production DMM code (not tests), this issue does not normally arise because DMM only decompresses (never builds) archives. However, `unset()` after every `PharData` operation is mandatory as a defensive measure.

### Download Must Stream to Disk

**Symptom:** `Allowed memory size of 268435456 bytes exhausted`

**Cause:** Using `CURLOPT_RETURNTRANSFER` to download a tarball loads the entire file into PHP memory. A module like Dolibarr core is ~150 MB; even smaller modules can approach 10-20 MB. Combined with PHP's `memory_limit` (typically 128-256 MB) and existing memory usage from Dolibarr's bootstrap, this triggers an OOM crash.

**Solution:** Always use `CURLOPT_FILE` to stream downloads directly to a file handle. See section 11 (Download) for the reference implementation. This applies to both the standalone DMM module and the embedded `DMMClient` class.

### GitHub Tarball Wrapper Directory

**Symptom:** Module installed but Dolibarr can't find it — wrong directory structure.

**Cause:** GitHub tarballs always contain a single top-level wrapper directory named `{owner}-{repo}-{short_hash}/`. Extracting the tarball directly into `/custom/` would create `/custom/myorg-mymodule-abc1234/` instead of `/custom/mymodule/`.

**Solution:** After extraction, DMM detects the wrapper (single top-level directory), reads the contents, and moves them to the correct target path based on `module_id`. The wrapper directory is then deleted.

### dolEncrypt Not Available in CLI

**Symptom:** Preflight diagnostic reports `dolEncrypt/dolDecrypt` as unavailable.

**Cause:** Running the preflight script from CLI without Dolibarr's full bootstrap (via `master.inc.php`) means the encryption functions are not loaded. This is expected — in the actual web context where DMM runs, these functions are always available (Dolibarr 13+).

**Solution:** This is informational only. The preflight tool flags it as a warning, not a failure. If `dolEncrypt` is truly unavailable in the web context (Dolibarr < 13), DMM falls back to base64 encoding with a clear warning in the admin UI that tokens are not encrypted.
