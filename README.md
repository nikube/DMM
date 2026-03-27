# DoliModuleManager (DMM)

Module manager with GitHub integration for discovering, installing and updating third-party Dolibarr modules.

## Features

- **Module catalog** — browse, install, update and rollback modules from a single dashboard
- **GitHub integration** — check releases, parse `dmm.json` manifests, download tarballs
- **Token management** — store encrypted GitHub tokens (PAT or Fine-Grained) for private repos
- **Auto-discovery** — add a token and DMM scans all accessible repos for DMM-compatible modules
- **DMMHub** — subscribe to curated module directories via `dmmhub.json` URLs
- **Public repos** — add public GitHub repos without a token
- **Compatibility check** — semver resolution against Dolibarr and PHP version constraints
- **Backup & rollback** — automatic backup before every update, one-click restore
- **Self-update** — DMM can update itself, with auto-reactivation and a rescue script
- **Preflight diagnostics** — web-based system check (PHP, permissions, GitHub connectivity)
- **Auto-check** — automatically check for updates when browsing DMM pages

## Requirements

- Dolibarr 14.0+
- PHP 8.0+
- PHP extensions: curl, json, phar, openssl, zlib, mbstring

## Installation

1. Copy the `dolimodulemanager/` directory to your Dolibarr `htdocs/custom/` folder
2. Fix permissions: `chown -R www-data:www-data /path/to/custom/dolimodulemanager && chmod -R u+w /path/to/custom/dolimodulemanager`
3. Activate the module from **Home > Setup > Modules** (search for "DoliModuleManager")
4. The preflight diagnostic page will open automatically on first access

## Usage

1. Go to **Settings** tab to add a GitHub token or a public repository
2. DMM auto-discovers modules accessible by the token
3. Go to **Dashboard** to see all modules, check for updates, install or update
4. Click on a module to see details, compatibility matrix, changelog, and rollback options

## Making a module DMM-compatible

Add a `dmm.json` file at the root of your GitHub repository:

```json
{
  "schema_version": "1",
  "module_id": "mymodule",
  "name": "My Module",
  "description": "What it does",
  "author": "Your Name",
  "license": "GPL-3.0-or-later",
  "repository": "owner/repo",
  "compatibility": {
    "1.x": {
      "dolibarr_min": "16.0.0",
      "dolibarr_max": "22.*",
      "php_min": "8.0"
    }
  }
}
```

Create GitHub releases with semantic version tags (e.g., `v1.0.0`).

## DMMHub

Create a `dmmhub.json` to list multiple modules in a single directory:

```json
{
  "schema_version": "1",
  "name": "My Module Directory",
  "modules": [
    { "repo": "owner/module-a", "public": true },
    { "repo": "owner/module-b", "public": false }
  ]
}
```

See [DMMHUB_SPECIFICATION.md](DMMHUB_SPECIFICATION.md) for the full specification.

## License

GPL-3.0-or-later

## Author

Nicolas - [AnatoleConseil.com](https://anatoleconseil.com/) — nz@anatoleconseil.com
