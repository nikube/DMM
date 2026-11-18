# dmmback — Licensed Module Server Specification

**Version:** 1.0.0-draft
**Date:** 2026-11-02
**Related:** [DMM Specification](DMM_SPECIFICATION.md), [DMMHub Specification](DMMHUB_SPECIFICATION.md)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Goals & Non-Goals](#2-goals--non-goals)
3. [Protocol](#3-protocol)
4. [Hosting](#4-hosting)
5. [DMM Integration](#5-dmm-integration)
6. [UI & User Flow](#6-ui--user-flow)
7. [Storage](#7-storage)
8. [Security Considerations](#8-security-considerations)
9. [Server Implementer Guide](#9-server-implementer-guide)
10. [Appendix A — Example responses](#appendix-a--example-responses)

---

## 1. Overview

A **dmmback server** lets a vendor sell Dolibarr modules and have customers install and update them from DMM, with nothing but a licence key: no GitHub account on the customer side, no git forge to host on the vendor side.

The customer registers the server URL and the licence key once in DMM (Add → *Licensed*). DMM then asks the server which modules the licence covers, lists them, and installs or updates them through four read-only HTTP endpoints. The server decides what the key may see — typically a proxy in front of the vendor's private GitHub repositories, but DMM does not care where the files come from.

`dmmback` is a third git host in DMM next to `github` and `gitlab`. It reuses the GitLab client code path (`DMMClient::gitlabApiCall()` with a `host` argument) and the existing release loop, so a server only has to speak the small protocol below.

The reference implementation is [`nikube/dmmback`](https://github.com/nikube/dmmback) (Python, FastAPI, SQLite, GitHub proxy).

## 2. Goals & Non-Goals

**Goals**

- One registration per server: URL + key. Modules appear by themselves.
- Perishable licences decided server-side (expiry, revocation, scope), DMM shows the server's own words.
- Zero database change in DMM; a server is ~300 lines in any language.
- The licence key never leaves DMM for any destination but its own server.

**Non-Goals**

- Branches, dev channel, `/tree/...` sub-directories: a dmmback module is **release-only**.
- Hubs: a dmmback server is not a hub, and `dmmhub.json` entries cannot point to one.
- Payments, invoices, e-mail: the server exposes licences, something else sells them.
- Periodic update checks: DMM has none for any host; checks run when the user asks.

## 3. Protocol

**Base URL** — the URL the customer registers, e.g. `https://modules.example.com`. Endpoints are relative to it (a path prefix is allowed: `https://example.com/dmm` → `https://example.com/dmm/v1/licence`).

**Request headers** sent by DMM on every call:

```
Authorization: Bearer <licence key>
Accept: application/json
User-Agent: DMM/1.0
```

**Errors** — JSON `{"message": "<one readable sentence>"}` with a meaningful status. DMM shows `message` verbatim ("Dmmback API error: …"), so write it for the customer, not for a log.

| Status | Meaning |
|---|---|
| 401 | Key unknown or revoked |
| 403 | Module not covered by this licence, or version released after the licence expired |
| 404 | Unknown module, unknown version, **or no release available** (never answer `200 []`) |
| 502 | Upstream (e.g. GitHub) unavailable |

### 3.1 `GET /v1/licence`

Licence status and the modules it covers. Served while the licence is **expired** too (the catalog still shows what was bought); only a revoked key gets 401.

```json
{
  "licence": {"label": "ACME SAS", "status": "active", "expires_at": "2027-11-02T23:59:59Z"},
  "modules": [
    {"module_id": "multifilter", "name": "MultiFilter", "description": "…", "latest_version": "0.1.5"}
  ]
}
```

- `status`: `active` or `expired`. `expires_at`: ISO 8601 UTC or `null` (perpetual).
- `module_id` is the folder name under `custom/` (`^[a-z0-9_]+$`). DMM uses `module_id`, `name`, `description`; `latest_version` is display-only. A server may add an `error` string to an entry whose metadata could not be read; DMM ignores unknown fields.

### 3.2 `GET /v1/modules/{module_id}/dmm.json`

The module manifest, raw, as defined in [DMM Specification §5](DMM_SPECIFICATION.md) (`schema_version` must be the string `"1"`). Read from the repository's default branch. Served to expired licences.

### 3.3 `GET /v1/modules/{module_id}/releases`

A JSON **array** in GitHub's vocabulary, so DMM's release loop consumes it unchanged:

```json
[
  {
    "version": "0.1.5",
    "tag_name": "v0.1.5",
    "name": "0.1.5",
    "body": "Release notes…\n\n<!-- dmm\ndolibarr_min: 19.0.0\ndolibarr_max: 24.*\nphp_min: 7.4\n-->",
    "released_at": "2026-10-11T09:12:00Z",
    "prerelease": false,
    "draft": false
  }
]
```

- DMM reads `tag_name` (fallback `name`), `body` (fallback `description`), `draft`, `prerelease`; it skips drafts and prereleases, strips a leading `v` to get the version and picks the highest compatible one with `version_compare`. `version` and `released_at` are for humans and other clients.
- The `<!-- dmm … -->` block in `body` sets per-release compatibility exactly as on GitHub ([DMM Specification §6](DMM_SPECIFICATION.md)).
- All releases in one response: DMM reads a single page.
- **Expired licence:** list only releases published on or before `expires_at`. **Nothing to list → 404** with a message saying why.

### 3.4 `GET /v1/modules/{module_id}/archive/{ref}.tar.gz`

The module archive for one version. DMM sends `ref` as the **bare version** (`0.1.5`, the tag without its `v`); a server should also accept the raw tag.

- Final response **200** with the gzip'd tar body (redirects are followed, but the last hop must be 200). `Content-Type: application/gzip`.
- Exactly **one top-level directory**, any name (GitHub's `owner-repo-sha/` is fine). The module descriptor `core/modules/mod*.class.php` is found inside it; its `$this->version` must equal the version.
- **Expired licence:** 403 for a version released after `expires_at`.

## 4. Hosting

- HTTPS with a valid certificate (DMM's curl verifies TLS). Plain `http://` works and is only meant for local testing (e.g. `http://172.20.0.1:8787`).
- The host part of the URL must contain a dot (`localhost` is rejected by DMM's URL parser; use an IP).
- Any stack: the reference server is one Python module on SQLite; the files come from GitHub through a server-side token, nothing is stored.
- Keep `/v1/licence` cheap: the Add tab calls it on every render for every registered licence.

## 5. DMM Integration

### 5.1 Registration

`admin/add.php?catalog=dmmback`, action `addlicence`: DMM calls `GET /v1/licence` with the key; on 200 it stores one `llx_dmm_token` row per server (`token_type='dmmback'`, `github_owner=<server URL>`, encrypted key, label from the licence). Registering the same server again replaces the key (renewal). Dev-mode users also see and edit the row in Sources → Tokens (type "Licence key").

### 5.2 Module row convention (`llx_dmm_module`)

| Column | Value |
|---|---|
| `module_id` | from the server's `modules[].module_id` |
| `git_host` | `dmmback` |
| `git_base_url` | server URL, as registered |
| `github_repo` | `<server host>/<module_id>` (keeps every `explode('/')` site working; shown on the dashboard) |
| `source` | `dmmback` (badge "Licensed") |
| `fk_dmm_token` | `NULL` — the key is resolved by URL, so a re-registered licence is picked up by every row |
| `name`, `description` | from the licence entry |

### 5.3 Key resolution

`DMMClient::dmmbackTokenFor($url)` returns the active `dmmback` token whose server URL is a case-insensitive prefix of `$url`. It serves both the parsers (a pasted `https://modules.example.com/multifilter` becomes a `dmmback` row with `module_id = multifilter`) and `checkUpdate()` / `installOrUpdate()` (`resolveHostToken()`).

### 5.4 Check and install flow

1. `checkUpdate()`: `gitListReleases()` → `GET /v1/modules/{id}/releases`; `gitFetchManifest()` → `GET /v1/modules/{id}/dmm.json`; then the common loop (compatibility, highest version, cache). No default-branch or branch-SHA calls: `gitDefaultBranch()`, `fetchBranchSha()` and `listBranches()` return `null` for `dmmback`, so a failed `/releases` lands in the normal error path and shows the server's `message`.
2. `installOrUpdate()`: `gitDownloadArchive()` → `GET /v1/modules/{id}/archive/{version}.tar.gz` streamed to disk (`streamDownload()` already sends `Authorization: Bearer` for non-GitLab hosts); extraction, backup, swap and registry update are the GitHub/GitLab code unchanged.
3. The module page offers Install/Update only once a check has cached `cache_latest_compatible`; the Add action runs `checkUpdate()` right after creating the row so the button is there on arrival.

## 6. UI & User Flow

- **Add → Licensed** (`add.php?catalog=dmmback`, visible to every DMM user, not only dev mode): registration form (server URL + key), then per licence a header (label · status badge · expiry) and a table Module / Description / Version / Action. Action is *Installed* (registered and on disk), *Install* (registered, not on disk → module page) or *Add* (creates the row and opens the module page).
- **Module page**: same as any module (versions, Install/Update, backups). The channel/branch picker is hidden for `dmmback` rows even in dev mode.
- **Dashboard**: repository column links to the server URL; source badge "Licensed".
- **Sources (dev mode)**: the licence appears in the token list with type "Licence key" and the server URL in the owner column; *Test* calls `/v1/licence`; *Discover* is not offered.

## 7. Storage

No schema change. Reused columns:

- `llx_dmm_token`: `token_type='dmmback'`, `github_owner` = server URL, `token` = key encrypted with `dolEncrypt()`, `use_for_public=0`.
- `llx_dmm_module`: see §5.2.

`DMMToken::fetchAll($activeOnly, $tokenType = 'github')` excludes licence keys by default; pass `'dmmback'` for licences only or `null` for everything (Sources list).

## 8. Security Considerations

- **A licence key only ever reaches its own server.** Every GitHub-facing loop over tokens goes through `fetchAll()` and therefore never sees it; `resolveToken()`'s "use for public" fallback excludes it; `DMMToken::validate()` probes `/v1/licence` instead of GitHub; branch helpers return early for `dmmback`.
- The key is stored encrypted like a GitHub PAT and masked in the UI.
- Servers should treat the key as a bearer credential: HTTPS only, rate-limit `/v1/licence`, never log the key.
- A module archive is executed as PHP on the customer's server: the customer trusts the vendor exactly as with DoliStore or a GitHub release. The server must only serve archives it built or fetched from a source it controls.

## 9. Server Implementer Guide

Minimal checklist for a server written from scratch:

1. Store licences: key, label, covered module ids (or all), `expires_at`, `revoked_at`.
2. Implement the four `GET` routes of §3 with `Authorization: Bearer`; answer `{"message": …}` on every error; never `200 []`.
3. Release objects: `tag_name`, `body`, `draft`, `prerelease` are what DMM reads. Put the `<!-- dmm … -->` block in `body` if compatibility differs from `dmm.json`.
4. Archive: one top-level directory, descriptor version equal to the tag without `v`, final status 200.
5. Expiry rule of your choice, applied in `/releases` (filter) and `/archive` (403). The reference server keeps versions published before expiry (JetBrains model).
6. Give the customer two strings: the server URL and the key. They go into Add → Licensed.

## Appendix A — Example responses

**401** `{"message": "Unknown or revoked licence key"}`

**403** `{"message": "Module 'multifilter' is not covered by this licence"}`

**403** `{"message": "Licence expired on 2026-06-30T23:59:59Z; version 0.1.6 was released after that date"}`

**404** `{"message": "No release available for module 'multifilter' (licence expired on 2026-06-30T23:59:59Z)"}`

**`/v1/licence` with an unreadable module** (metadata missing, licence still usable):

```json
{
  "licence": {"label": "ACME SAS", "status": "active", "expires_at": null},
  "modules": [
    {"module_id": "superstock", "name": null, "description": null, "latest_version": null, "error": "GitHub answered 401 for /repos/nikube/superstock/contents/dmm.json"}
  ]
}
```
