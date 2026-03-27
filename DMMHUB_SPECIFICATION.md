# DMMHub — Module Directory Specification

**Version:** 1.0.0-draft
**Date:** 2026-03-26
**Related:** [DMM Specification](DMM_SPECIFICATION.md)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Goals & Non-Goals](#2-goals--non-goals)
3. [Hub Format — `dmmhub.json`](#3-hub-format--hubjson)
4. [Hosting](#4-hosting)
5. [DMM Integration](#5-dmm-integration)
6. [Token Auto-Matching](#6-token-auto-matching)
7. [UI & User Flow](#7-ui--user-flow)
8. [Storage](#8-storage)
9. [Caching & Refresh](#9-caching--refresh)
10. [Security Considerations](#10-security-considerations)
11. [Hub Maintainer Guide](#11-hub-maintainer-guide)

---

## 1. Overview

DMMHub is a lightweight directory format for listing DMM-compatible Dolibarr modules. A hub is a single JSON file (`dmmhub.json`) hosted on GitHub or any HTTP endpoint, containing an array of module repository references.

DMM instances subscribe to hub URLs. On refresh, DMM fetches the hub file, iterates the listed modules, and registers them in the local module registry. The hub is an index of pointers — it does not contain module code, compatibility info, versions, or changelogs. That data lives in each module's own `dmm.json` and GitHub releases.

Multiple hubs can coexist. A community hub, a vendor hub, and a private company hub can all be subscribed simultaneously.

---

## 2. Goals & Non-Goals

### Goals

- Provide a simple, static directory that any developer or organization can publish.
- Allow DMM users to discover modules from a single URL instead of adding repos one by one.
- Support both public and private (token-gated) modules in the same hub.
- Require zero infrastructure — a GitHub repo with a JSON file is sufficient.
- Be forward-compatible — unknown fields in `dmmhub.json` are silently ignored.

### Non-Goals

- DMMHub is not a package registry. It does not host module code or assets.
- DMMHub does not handle authentication. Token management remains in DMM.
- DMMHub does not enforce module quality, licensing, or compatibility. It is a directory, not a marketplace.
- DMMHub does not replace `dmm.json`. Each module still needs its own manifest for compatibility and metadata.

---

## 3. Hub Format — `dmmhub.json`

### Schema

```json
{
  "schema_version": "1",
  "name": "<string>",
  "description": "<string|null>",
  "url": "<string|null>",
  "modules": [
    {
      "repo": "<owner/repo>",
      "public": "<bool>",
      "name": "<string|null>",
      "description": "<string|null>",
      "url": "<string|null>"
    }
  ]
}
```

### Field Reference

| Field | Type | Required | Description |
|---|---|---|---|
| `schema_version` | string | **yes** | Always `"1"` for this spec version. |
| `name` | string | **yes** | Human-readable hub name. Example: `"Anatole Consulting Modules"`. |
| `description` | string | no | Short description of the hub. |
| `url` | string | no | URL to the hub maintainer's website. |
| `modules` | array | **yes** | Array of module entries (see below). |

### Module Entry Fields

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `repo` | string | **yes** | — | GitHub repository in `owner/repo` format. |
| `public` | bool | no | `false` | If `true`, the repo is public and no token is needed. If `false` or omitted, a token is required. |
| `name` | string | no | — | Display name hint. Overridden by `dmm.json` name if the manifest is fetched. |
| `description` | string | no | — | Display description hint. Overridden by `dmm.json` description if fetched. |
| `url` | string | no | — | Link to the module's page (purchase, documentation). Displayed as "Get access" for private modules without a token. |

Unknown fields are silently ignored (forward-compatible).

### Full Example

```json
{
  "schema_version": "1",
  "name": "DMMHub — Community Directory",
  "description": "A curated list of DMM-compatible Dolibarr modules.",
  "url": "https://github.com/nikube/DMMHub",
  "modules": [
    {
      "repo": "nikube/DMM",
      "public": true,
      "name": "DoliModuleManager",
      "description": "Module manager with GitHub integration"
    },
    {
      "repo": "nikube/factufournisseur",
      "public": false,
      "name": "Supplier Invoice Generator",
      "url": "https://anatoleconseil.com/modules/factufournisseur"
    },
    {
      "repo": "somedev/stockadvanced",
      "public": true
    }
  ]
}
```

### Minimal Example

```json
{
  "schema_version": "1",
  "name": "My Modules",
  "modules": [
    { "repo": "myorg/module-a", "public": true },
    { "repo": "myorg/module-b" }
  ]
}
```

---

## 4. Hosting

A hub can be hosted anywhere that serves JSON over HTTP(S):

### GitHub (recommended)

Create a repo (e.g., `nikube/DMMHub`) with `dmmhub.json` at the root.

Raw URL:
```
https://raw.githubusercontent.com/nikube/DMMHub/main/dmmhub.json
```

GitHub API URL (alternative, works with tokens for private hubs):
```
https://api.github.com/repos/nikube/DMMHub/contents/dmmhub.json
```

### Self-hosted

Any web server serving the file:
```
https://modules.anatole-consulting.fr/dmmhub.json
```

### Private hub

A hub can itself be in a private repo. DMM will use a token to fetch it (same mechanism as fetching `dmm.json` — if the URL is a GitHub API URL, DMM sends the token).

---

## 5. DMM Integration

### Import Flow

When a user adds a hub URL or clicks "Refresh":

```
1. Fetch dmmhub.json from URL
   - If raw HTTP URL: simple GET request
   - If GitHub API URL: GET with token (if available)
2. Validate: schema_version must be "1"
3. For each module entry:
   a. Determine module_id:
      - Fetch dmm.json from the repo → use module_id field
      - Fallback: derive from repo name (lowercase, alphanumeric + underscore)
   b. If module_id already in llx_dmm_module → skip (already registered)
   c. If public: true → register with fk_dmm_token = NULL
   d. If public: false → attempt token auto-matching (see section 6)
   e. Populate metadata from dmm.json if available, else use hub hints
   f. Auto-detect if module is installed in /custom/
   g. Insert into llx_dmm_module
4. Report results to user (toast messages)
```

### Relationship with Existing Discovery

Hubs complement, not replace, the existing mechanisms:

| Mechanism | What it does | When to use |
|---|---|---|
| **Token discovery** | Scans all repos a token can access | When client has a vendor token |
| **Hub** | Lists curated repos from a URL | When browsing a community or vendor directory |
| **Manual public repo** | Adds a single public repo | One-off additions |

A module registered by one mechanism is not duplicated by another. The `module_id` + `github_repo` unique key in `llx_dmm_module` prevents duplicates.

---

## 6. Token Auto-Matching

When a hub lists a private module (`public: false` or default), DMM tries to find a local token that grants access:

```
For each private repo in hub.modules:
  For each active token in llx_dmm_token (status = 1):
    Call GET /repos/{owner}/{repo} with token
    If HTTP 200 → match found
      Register module with fk_dmm_token = this token's rowid
      Break (first match wins)
  If no token matches:
    Register module with fk_dmm_token = NULL
    Set cache_last_error = "No token with access to this repo"
    Module appears in dashboard as "Needs token"
```

### Optimization: owner-based short-circuit

Once a token matches for a specific GitHub owner, try that token first for other repos from the same owner. This reduces API calls from `modules × tokens` to approximately `owners × tokens + modules`.

### Rate limit awareness

Token matching makes 1 API call per (private module × token) in the worst case. For a hub with 10 private modules and 3 tokens, that's up to 30 calls. Acceptable for a manual action (add hub / refresh).

---

## 7. UI & User Flow

### Settings Page — Hub Section

At the top of the Settings page (first section):

```
┌───────────────────────────────────────────────────────────────────────┐
│ Module Hubs                                                           │
├────────────┬──────────────┬────────┬──────────┬─────────┬────────────┤
│ Name       │ URL          │ Status │ Last     │ Modules │ Action     │
│            │              │        │ check    │         │            │
├────────────┼──────────────┼────────┼──────────┼─────────┼────────────┤
│ DMMHub     │ https://...  │ [ON]   │ 27/03/26 │ 4       │ 🔍 🔄 🗑  │
├────────────┼──────────────┼────────┼──────────┼─────────┼────────────┤
│ Add hub URL: [_______________________________________]       [Add]    │
└───────────────────────────────────────────────────────────────────────┘
```

Features:
- **Enable/disable toggle**: deactivated hubs are ignored during auto-check
- **Inspect button** (🔍): fetches the hub and shows its content as toast messages (hub name, description, module list with public/private status)
- **Refresh button** (🔄): re-imports modules from the hub
- **Remove button** (🗑): removes the hub and its cache

### Toast Messages on Add/Refresh

```
Hub: DMMHub — Community Directory
5 modules listed | 3 public, 2 private
2 new module(s) registered
1 module(s) matched to token "Anatole Consulting"
1 module(s) need a token (not accessible with current tokens)
1 module(s) already registered
```

### Dashboard Display

Modules imported from a hub appear in the normal dashboard module list. No visual distinction — they behave identically to modules added via token discovery or manual entry.

Modules with `fk_dmm_token = NULL` and `public = false` show a "Needs token" badge.

---

## 8. Storage

Hub URLs and cache are stored in `llx_dmm_setting`:

| Key | Value | Description |
|---|---|---|
| `hub_urls` | JSON array | `[{"url":"https://...dmmhub.json","enabled":1}, ...]` |
| `hub_cache_{md5(url)}` | JSON string | Cached `dmmhub.json` content (for display: name, module count) |
| `hub_last_fetch_{md5(url)}` | datetime string | Last successful fetch timestamp |

No new database table required.

---

## 9. Caching & Refresh

- Hub content is fetched only when:
  - A new hub URL is added
  - The user clicks "Refresh" on a specific hub
- Cached hub content is stored in `llx_dmm_setting` for display purposes (hub name, module count).
- Hub refresh does NOT re-fetch `dmm.json` for already-registered modules — that's handled by the normal module check cycle.
- Stale hub cache has no impact on installed modules — the hub is only used for discovery, not for version checking.

---

## 10. Security Considerations

### URL Validation

- Only `https://` URLs are accepted (no `http://`, no local paths, no `file://`).
- Exception: `http://localhost` and `http://127.0.0.1` allowed for development.
- URL length capped at 500 characters.

### Content Validation

- Response must be valid JSON with `schema_version: "1"`.
- `modules` array capped at 500 entries (prevent abuse).
- `repo` field validated against `owner/repo` format (alphanumeric, hyphens, underscores, dots).
- Total response size capped at 1MB.

### Private Hubs

- A hub hosted in a private GitHub repo is accessed using the same token mechanism as module repos.
- The hub URL can use the GitHub API format: `https://api.github.com/repos/{owner}/{repo}/contents/dmmhub.json`
- DMM will try all active tokens when fetching a hub URL that returns 401/404.

### Trust

- Users should only add hub URLs from sources they trust.
- A malicious hub could list repos that, when accessed, trigger token-authenticated requests to attacker-controlled repos. Mitigation: DMM only calls GitHub API endpoints (`api.github.com`), never arbitrary URLs from hub entry content. The `repo` field is always resolved to `https://api.github.com/repos/{owner}/{repo}/...`.
- A hub cannot execute code — it is a static JSON file parsed by DMM.

---

## 11. Hub Maintainer Guide

### Creating a Hub (5 Minutes)

1. Create a GitHub repo (e.g., `your-org/dmm-hub`).
2. Add a `dmmhub.json` file at the root:

```json
{
  "schema_version": "1",
  "name": "Your Organization Modules",
  "modules": [
    { "repo": "your-org/module-one", "public": true },
    { "repo": "your-org/module-two", "public": false, "name": "Premium Module" }
  ]
}
```

3. Share the raw URL with your users:
   `https://raw.githubusercontent.com/your-org/dmm-hub/main/dmmhub.json`

### Adding a Module to the Hub

Edit `dmmhub.json` and add an entry to the `modules` array. The module repo must contain a `dmm.json` manifest for full DMM compatibility (version checking, compatibility matrix). Without `dmm.json`, the module will appear in DMM but with "unverified" compatibility.

### Public vs Private

- `"public": true` — anyone can install this module without a token. Use for open-source modules.
- `"public": false` (or omitted) — a GitHub token with access to the repo is required. Use for commercial/private modules. The client's token scope determines what they can access.

### Organizing Multiple Hubs

| Hub type | Maintained by | Contents |
|---|---|---|
| **Community hub** | Open-source community (PRs welcome) | All known open-source DMM modules |
| **Vendor hub** | Module vendor/editor | Vendor's modules (free + commercial) |
| **Company hub** | Internal IT team | Private modules for internal use |

Users can subscribe to multiple hubs simultaneously. Duplicate repos across hubs are handled gracefully (registered once, subsequent encounters skipped).

---

## Appendix A — Glossary

| Term | Definition |
|---|---|
| **Hub** | A `dmmhub.json` file listing DMM-compatible module repos. |
| **Hub URL** | The HTTP(S) URL pointing to a `dmmhub.json` file. |
| **Public module** | A module in a public GitHub repo, accessible without authentication. |
| **Private module** | A module in a private GitHub repo, requiring a Fine-Grained Token with repo access. |
| **Token auto-matching** | DMM's mechanism to automatically find which local token grants access to a private repo listed in a hub. |
| **Discovery** | The process of scanning repos (via token, hub, or manual entry) to populate the local module registry. |
