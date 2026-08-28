<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/DMMClient.class.php
 * \ingroup dolimodulemanager
 * \brief   Core engine for DoliModuleManager — works standalone and embedded
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Class DMMClient
 *
 * Handles GitHub API communication, version resolution, module installation,
 * updates and rollbacks. Can operate in two modes:
 * - Standalone: uses llx_dmm_* tables (when DMM module is installed)
 * - Embedded: uses llx_const for token/repo storage (when bundled in another module)
 */
class DMMClient
{
	/**
	 * How long a cached dmm.json lookup is trusted without asking GitHub again.
	 *
	 * Past this, the entry is not discarded — it is revalidated with If-None-Match,
	 * which costs a round trip but no rate-limit quota. Manifests change on the
	 * order of releases, so an hour keeps a burst of refreshes off the network
	 * while never hiding a change for long.
	 */
	const MANIFEST_CACHE_TTL = 3600;

	/**
	 * Where DMM itself comes from, so it can register its own row without asking
	 * anyone. Hardcoded rather than read from a manifest: dmm.json lives at the
	 * repository root, outside the module directory, so it is absent from the
	 * released zip and from every install made through it.
	 */
	const SELF_MODULE_ID = 'dolimodulemanager';
	const SELF_REPO = 'nikube/DMM';

	/** @var array<string,string>|null Memoised community index versions, per request */
	private $communityVersions = null;

	/** @var DoliDB */
	private $db;

	/** @var bool True if DMM tables exist (standalone mode) */
	private $standalone = false;

	/** @var string Last error message */
	public $error = '';

	/** @var array Last errors */
	public $errors = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB|null $db Database handler. If null, uses global $db.
	 */
	public function __construct($db = null)
	{
		if ($db === null) {
			global $db;
			$this->db = $db;
		} else {
			$this->db = $db;
		}

		$this->standalone = $this->tableExists('dmm_token');
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Check if an update is available for a module.
	 *
	 * @param  string      $module_id Module identifier (directory name in /custom/)
	 * @param  string|null $token     GitHub token. If null, auto-resolved.
	 * @param  string|null $repo      GitHub repo (owner/repo). If null, auto-resolved.
	 * @return array|null             Update info or null on error
	 */
	public function checkUpdate($module_id, $token = null, $repo = null)
	{
		// DoliStore-sourced modules don't live on Git — short-circuit to the
		// catalog API instead of trying to resolve a repo we never had.
		if ($this->standalone) {
			$probe = $this->loadModuleRow($module_id);
			if ($probe !== null && ($probe->source ?? '') === 'dolistore' && !empty($probe->dolistore_id)) {
				return $this->checkDolistoreUpdate($module_id, (int) $probe->dolistore_id);
			}
		}

		$repo = $this->resolveRepo($module_id, $repo);

		if (empty($repo)) {
			$this->error = 'Cannot resolve GitHub repository for '.$module_id;
			return null;
		}

		list($owner, $repoName) = explode('/', $repo, 2);

		// Load the module row early so we know which git host (github/gitlab) to talk to
		// and whether a dev branch is declared. Falls back to github for embedded mode.
		$modRow = $this->standalone ? $this->loadModuleRow($module_id) : null;
		$gitHost = ($modRow && !empty($modRow->git_host)) ? $modRow->git_host : 'github';
		$gitBaseUrl = ($modRow && !empty($modRow->git_base_url)) ? $modRow->git_base_url : null;

		// Token resolution is GitHub-only. llx_dmm_token only stores GitHub PATs, so
		// sending one as a GitLab PRIVATE-TOKEN header triggers 401 Unauthorized on
		// inligit.fr. v1.6.x is public-only for GitLab — no cross-host tokens.
		if ($gitHost === 'github') {
			$token = $this->resolveToken($module_id, $token);
		} else {
			$token = null;
		}

		// Heal a "Private / No token" row in place: if the row was imported via a hub
		// with fk_dmm_token = NULL and cache_last_error = "No token with access...",
		// probe active tokens now. On a hit, persist the match so future calls use it
		// and the Private badge clears on the next page load.
		//
		// Not only hub rows: a module registered by the local scan before any token
		// existed is in the same state, except its cached error is the raw GitHub
		// "Not Found" (private repo, unauthenticated). So probe whenever the row has
		// no credential and none could be resolved — public repos cost one extra
		// call the first time, then the match is persisted.
		if ($gitHost === 'github' && $modRow !== null && empty($modRow->fk_dmm_token) && $token === null) {
			$match = $this->tryMatchTokenForRepo($owner, $repoName);
			if ($match !== null) {
				$modRow->fk_dmm_token = $match['token_id'];
				$modRow->cache_last_error = null;
				global $user;
				$modRow->update($user);
				$token = $match['plain_token'];
			}
		}

		// Branch tracking: short-circuit to branch HEAD SHA tracking.
		//
		// Two different things used to share this flag. Opting a module onto a dev
		// channel is a developer-mode feature. But a module distributed from a
		// branch because its repo publishes no releases — every module in the
		// Dolibarr community index — is a property of the repo, and gating it on
		// developer mode made those installs fall back to hunting for a tag that
		// does not exist. dmm_module_tracks_branch() tells the two apart.
		if ($this->standalone && $modRow && dmm_module_tracks_branch($modRow)) {
			return $this->checkDevBranchUpdate($module_id, $owner, $repoName, $modRow->branch_dev, $token, $gitHost, $gitBaseUrl);
		}

		// Fetch releases (host-aware). For hosts that don't expose /releases (e.g.
		// self-hosted GitLab with the endpoint admin-locked, or repos that never
		// tagged a release), fall back to branch-HEAD tracking so the module is
		// still usable for install/update.
		$releasesResult = $this->gitListReleases($gitHost, $gitBaseUrl, $owner, $repoName, $token);
		$releases = array();
		$resolvedFallbackBranch = null;
		$releasesReachable = ($releasesResult !== null && $releasesResult['code'] === 200);
		if ($releasesReachable) {
			$decoded = json_decode($releasesResult['body'], true);
			if (is_array($decoded)) {
				$releases = $decoded;
			}
		}
		// A repo that never tagged anything answers 200 with [], which is the same
		// situation as an unreachable endpoint: there is no tag to install. Testing
		// only reachability left those repos with no compatible version at all —
		// Dolibarr/dolibarr-community-modules, which hosts the community modules,
		// is exactly this case.
		if (!$releasesReachable || empty($releases)) {
			// Branch-HEAD fallback: read the row's declared branch and use its SHA as a
			// synthetic "release". This is the same mechanism as the dev channel, applied
			// automatically when no releases are visible. When the row declares no branch,
			// resolve the repo's REAL default branch (e.g. open-dsi GitLab defaults to a
			// year-named branch like "2026", not master/main); only guess as a last resort.
			if ($modRow && !empty($modRow->branch)) {
				$fallbackBranch = $modRow->branch;
			} else {
				$fallbackBranch = $this->gitDefaultBranch($owner, $repoName, $token, $gitHost, $gitBaseUrl);
				if (empty($fallbackBranch)) {
					$fallbackBranch = ($gitHost === 'gitlab' ? 'master' : 'main');
				}
			}
			$fallbackSha = $this->fetchBranchSha($owner, $repoName, $fallbackBranch, $token, $gitHost, $gitBaseUrl);
			// Manifests can outlive a branch rename (main <-> master). Do not let a
			// stale declared branch permanently break refresh: retry the repository's
			// authoritative default branch and heal the cached row on success.
			if ($fallbackSha === null && $modRow && !empty($modRow->branch)) {
				$defaultBranch = $this->gitDefaultBranch($owner, $repoName, $token, $gitHost, $gitBaseUrl);
				if (!empty($defaultBranch) && $defaultBranch !== $fallbackBranch) {
					$defaultSha = $this->fetchBranchSha($owner, $repoName, $defaultBranch, $token, $gitHost, $gitBaseUrl);
					if ($defaultSha !== null) {
						$fallbackBranch = $defaultBranch;
						$fallbackSha = $defaultSha;
						$resolvedFallbackBranch = $defaultBranch;
					}
				}
			}
			if ($fallbackSha === null) {
				$errorBody = $releasesResult['body'] ?? 'connection failed';
				$decoded = json_decode($errorBody, true);
				if (is_array($decoded) && !empty($decoded['message'])) {
					$errorBody = $decoded['message'];
				}
				$this->error = ucfirst($gitHost).' API error: '.$errorBody;
				if ($this->standalone) {
					$this->updateModuleCache($module_id, array('error' => $this->error));
				}
				return null;
			}
			$releases = array(array(
				'tag_name' => $fallbackBranch,
				'body' => '',
				'_synthetic_sha' => $fallbackSha,
			));
		}

		// Fetch manifest (host-aware). Pass module_id to bypass schema check for self-update.
		$manifestBranch = $resolvedFallbackBranch !== null ? $resolvedFallbackBranch : ($modRow ? $modRow->branch : null);
		$manifest = $this->gitFetchManifest($gitHost, $gitBaseUrl, $owner, $repoName, $manifestBranch, $token, $module_id);

		// Get current environment
		$dolibarrVersion = DOL_VERSION;
		$phpVersion = PHP_VERSION;
		$installedVersion = $this->getInstalledVersion($module_id);

		// A branch install records "dev:{sha12}" in the registry while the on-disk
		// descriptor still declares a semver. Comparing a branch SHA against that
		// semver can never match, so prefer the registry value — the same reasoning
		// checkDevBranchUpdate() already applies on the dev channel.
		if ($this->standalone && $modRow && !empty($modRow->installed_version)
			&& strpos($modRow->installed_version, 'dev:') === 0) {
			$installedVersion = $modRow->installed_version;
		}

		// Resolve compatible versions
		$latestVersion = null;
		$latestCompatible = null;
		$latestChangelog = '';
		$latestTag = '';
		$latestVerified = false;

		$usedBranchFallback = false;
		foreach ($releases as $release) {
			if (!empty($release['draft']) || !empty($release['prerelease'])) {
				continue;
			}

			// Branch-HEAD fallback: a synthetic entry with _synthetic_sha. Produce a
			// "dev:{sha12}" pseudo-version that version_compare will treat as a
			// string — the update-available check below handles it specially.
			if (!empty($release['_synthetic_sha'])) {
				// Same "dev:{sha12}" shape the dev channel and installOrUpdate() use,
				// so what a branch install records is exactly what this compares to —
				// unless the source publishes a version of its own, in which case that
				// is the better answer. See publishedBranchVersion().
				$shortSha = substr($release['_synthetic_sha'], 0, 12);
				$publishedVersion = $this->publishedBranchVersion($modRow);
				$latestVersion = $publishedVersion !== null ? $publishedVersion : 'dev:'.$shortSha;
				$latestCompatible = $latestVersion;
				$latestChangelog = '';
				$latestTag = $release['tag_name'];
				$latestVerified = false;
				$usedBranchFallback = true;
				if ($publishedVersion !== null) {
					// Compare the two version statements, not a semver against a commit.
					$installedVersion = $this->getInstalledVersion($module_id) ?: $installedVersion;
				}
				break;
			}

			// GitHub uses tag_name; GitLab uses tag_name too, but some self-hosted
			// versions return only "name". Accept either.
			$tag = $release['tag_name'] ?? ($release['name'] ?? '');
			$version = ltrim($tag, 'vV');
			if (empty($version)) {
				continue;
			}

			// Track absolute latest
			if ($latestVersion === null || version_compare($version, $latestVersion, '>')) {
				$latestVersion = $version;
			}

			// Get compatibility data for this release. GitHub: body. GitLab: description.
			$releaseBody = $release['body'] ?? ($release['description'] ?? '');
			$compat = $this->resolveCompatibility($version, $manifest, $releaseBody);
			$verified = ($compat !== null);

			// Check compatibility
			if ($compat !== null) {
				if (!$this->isCompatible($compat, $dolibarrVersion, $phpVersion)) {
					continue;
				}
			}
			// If no compat data, treat as compatible (unverified)

			if ($latestCompatible === null || version_compare($version, $latestCompatible, '>')) {
				$latestCompatible = $version;
				$latestChangelog = $releaseBody;
				$latestTag = $tag;
				$latestVerified = $verified;
			}
		}

		$updateAvailable = false;
		if ($latestCompatible !== null && $installedVersion !== null) {
			// A branch fallback normally yields a SHA, which only equality can compare.
			// When the source published a version instead, latestCompatible is a semver
			// and ordering applies again — otherwise a republished older version would
			// read as an update just for differing.
			$comparingShas = ($usedBranchFallback && strpos((string) $latestCompatible, 'dev:') === 0);
			if ($comparingShas) {
				// Compare SHA strings (not semver) — any difference means an update.
				$updateAvailable = ($installedVersion !== $latestCompatible);
			} else {
				$updateAvailable = version_compare($latestCompatible, $installedVersion, '>');
			}
		}

		$result = array(
			'update_available'         => $updateAvailable,
			'installed_version'        => $installedVersion,
			'latest_version'           => $latestVersion,
			'latest_compatible_version' => $latestCompatible,
			'changelog'                => $latestChangelog,
			'download_tag'             => $latestTag,
			'verified'                 => $latestVerified,
			'checked_at'               => gmdate('c'),
		);

		// Update cache and installed status if standalone
		if ($this->standalone) {
			$cacheUpdate = array(
				'latest_version'    => $latestVersion,
				'latest_compatible' => $latestCompatible,
				'changelog'         => $latestChangelog,
				'etag'              => $releasesResult['etag'] ?? null,
				'manifest_json'     => !empty($manifest) ? json_encode($manifest) : null,
			);
			// Persist branch/branch_dev from manifest so the channel selector knows
			// whether to show the Dev option without re-fetching the manifest.
			if (is_array($manifest)) {
				if (isset($manifest['branch'])) {
					$cacheUpdate['branch'] = (string) $manifest['branch'];
				}
				if (isset($manifest['branch_dev'])) {
					$cacheUpdate['branch_dev'] = (string) $manifest['branch_dev'];
				}
			}
			if ($resolvedFallbackBranch !== null) {
				$cacheUpdate['branch'] = $resolvedFallbackBranch;
			}
			$this->updateModuleCache($module_id, $cacheUpdate);

			// Auto-detect installed status from filesystem
			if ($installedVersion !== null) {
				$this->syncInstalledStatus($module_id, $installedVersion);
			}
		}

		return $result;
	}

	/**
	 * Update-check counterpart for DoliStore-sourced modules.
	 *
	 * Reads the latest module_version straight from the public DoliStore catalog
	 * (cached 24h on disk by DMMDolistoreClient) and compares with the descriptor
	 * version installed under custom/. Returns the same shape as checkUpdate().
	 *
	 * @param  string $module_id      DMM module identifier
	 * @param  int    $dolistore_id   Upstream product id
	 * @return array|null
	 */
	private function checkDolistoreUpdate($module_id, $dolistore_id)
	{
		dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
		$ds = new DMMDolistoreClient();
		$product = $ds->findProductById($dolistore_id);
		if ($product === null) {
			$this->error = $ds->error ?: 'DoliStore product '.$dolistore_id.' not found';
			if ($this->standalone) {
				$this->updateModuleCache($module_id, array('error' => $this->error));
			}
			return null;
		}
		$latestVersion = (string) ($product['module_version'] ?? '');
		if ($latestVersion === '') {
			// The catalog was built from the web listing, which does not carry the
			// version — resolve this one product directly. Costs one request, and
			// only in the mode that would otherwise never report an update.
			$latestVersion = (string) $ds->fetchProductVersion($dolistore_id);
		}
		$installedVersion = $this->getInstalledVersion($module_id);

		$updateAvailable = false;
		if ($latestVersion !== '' && $installedVersion !== null) {
			$updateAvailable = version_compare($latestVersion, $installedVersion, '>');
		} elseif ($latestVersion !== '' && $installedVersion === null) {
			// not installed yet — there's something to "install" if the row was added
			// to the registry but never deployed.
			$updateAvailable = true;
		}

		if ($this->standalone) {
			$cacheUpdate = array(
				'changelog'     => '',
				'etag'          => null,
				'manifest_json' => null,
			);
			if ($latestVersion !== '') {
				$cacheUpdate['latest_version'] = $latestVersion;
				$cacheUpdate['latest_compatible'] = $latestVersion;
			} else {
				// Say why the check produced nothing instead of leaving the card on a
				// bare "Latest: -". updateCache() guards on isset(), not on '', so
				// writing the empty string here would also erase a version resolved
				// by an earlier successful check.
				$cacheUpdate['error'] = $ds->error
					?: 'No version published on DoliStore for product '.$dolistore_id;
			}
			$this->updateModuleCache($module_id, $cacheUpdate);
			if ($installedVersion !== null) {
				$this->syncInstalledStatus($module_id, $installedVersion);
			}
		}

		return array(
			'update_available'         => $updateAvailable,
			'installed_version'        => $installedVersion,
			'latest_version'           => $latestVersion,
			'latest_compatible_version' => $latestVersion,
			'changelog'                => '',
			'download_tag'             => 'dolistore-'.$dolistore_id,
			'verified'                 => false,
			'checked_at'               => gmdate('c'),
		);
	}

	/**
	 * Download and install/update a module.
	 *
	 * @param  string      $module_id Module identifier
	 * @param  string      $tag       Git ref to install — a tag (e.g., 'v1.3.0') for the
	 *                                stable channel, or a branch name (e.g., 'develop') for
	 *                                the dev channel. GitHub's /tarball/{ref} accepts both.
	 * @param  string|null $token     GitHub token
	 * @param  string|null $repo      GitHub repo (owner/repo)
	 * @param  string      $channel   'stable' (default) or 'dev'. When 'dev', the installed
	 *                                version is recorded as 'dev:{short_sha}' instead of $tag.
	 * @return array                  Result: ['success' => bool, 'message' => string, 'backup_path' => string|null]
	 */
	public function installOrUpdate($module_id, $tag, $token = null, $repo = null, $channel = 'stable')
	{
		$module_id = $this->sanitizeModuleId($module_id);
		if ($module_id === false) {
			return array('success' => false, 'message' => 'Invalid module ID', 'backup_path' => null);
		}

		$repo = $this->resolveRepo($module_id, $repo);

		if (empty($repo)) {
			return array('success' => false, 'message' => 'Cannot resolve GitHub repository', 'backup_path' => null);
		}

		list($owner, $repoName) = explode('/', $repo, 2);

		// Load the module row (standalone mode) so we know git host + subdir.
		$modRow = $this->standalone ? $this->loadModuleRow($module_id) : null;
		$gitHost = ($modRow && !empty($modRow->git_host)) ? $modRow->git_host : 'github';
		$gitBaseUrl = ($modRow && !empty($modRow->git_base_url)) ? $modRow->git_base_url : null;
		$subdir = ($modRow && !empty($modRow->subdir)) ? $modRow->subdir : null;

		// Token resolution is GitHub-only. See checkUpdate() for the full rationale.
		if ($gitHost === 'github') {
			$token = $this->resolveToken($module_id, $token);
		} else {
			$token = null;
		}

		// Pre-flight checks
		$customDir = DOL_DOCUMENT_ROOT.'/custom/';
		$targetDir = $customDir.$module_id;

		if (!is_writable($customDir)) {
			return array('success' => false, 'message' => 'Cannot write to '.$customDir, 'backup_path' => null);
		}

		if ($this->isCoreModule($module_id)) {
			return array('success' => false, 'message' => 'Cannot overwrite core Dolibarr module: '.$module_id, 'backup_path' => null);
		}

		// Check disk space (warn if < 50MB)
		$freeSpace = @disk_free_space($customDir);
		if ($freeSpace !== false && $freeSpace < 50 * 1024 * 1024) {
			return array('success' => false, 'message' => 'Low disk space: '.round($freeSpace / 1024 / 1024, 1).'MB free', 'backup_path' => null);
		}

		$isUpdate = is_dir($targetDir);

		// Check write permissions on existing module directory (critical for updates)
		if ($isUpdate) {
			$permError = $this->checkWritePermissions($targetDir);
			if ($permError !== null) {
				$phpUser = function_exists('dmm_get_php_user') ? dmm_get_php_user('unknown') : 'unknown';
				return array('success' => false, 'message' => 'Permission denied: '.$permError.' — PHP runs as "'.$phpUser.'". Fix with: chown -R '.$phpUser.':'.$phpUser.' '.$targetDir.' && chmod -R u+w '.$targetDir, 'backup_path' => null);
			}
		}
		$backupPath = null;

		// Backup existing module before update
		if ($isUpdate) {
			$backupResult = $this->createBackup($module_id, $tag);
			if (!$backupResult['success']) {
				return array('success' => false, 'message' => 'Backup failed: '.$backupResult['message'], 'backup_path' => null);
			}
			$backupPath = $backupResult['backup_path'];
		}

		// Download tarball
		$tempDir = $this->getTempDir();
		$tarGzPath = $tempDir.'/dmm_'.$module_id.'_'.uniqid().'.tar.gz';

		// Until the module directory is actually modified below, any failure leaves the
		// live module intact — so we just clean temp files and return, NEVER restore
		// (a restore here would needlessly delete-and-recopy a healthy module).
		$downloadResult = $this->gitDownloadArchive($gitHost, $gitBaseUrl, $owner, $repoName, $tag, $token, $tarGzPath);
		if (!$downloadResult['success']) {
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Download failed: '.$downloadResult['message'], 'backup_path' => $backupPath);
		}

		// Extract
		$extractDir = $tempDir.'/dmm_extract_'.uniqid();
		$extractResult = $this->extractTarball($tarGzPath, $extractDir);
		if (!$extractResult['success']) {
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Extraction failed: '.$extractResult['message'], 'backup_path' => $backupPath);
		}

		// Find the actual module content (GitHub/GitLab tarballs wrap content in one
		// top-level directory). If a subdir is declared (e.g. monorepo entry), look
		// inside wrapper/{subdir}/.
		$sourceDir = $this->findModuleRoot($extractDir, $module_id, $subdir);
		if ($sourceDir === false) {
			$this->cleanupDir($extractDir);
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Could not find module content in archive', 'backup_path' => $backupPath);
		}

		// Verify the descriptor exists in the SOURCE before touching the target, so a
		// restructured repo / wrong tag can never overwrite a healthy module (an
		// in-place update keeps the old descriptor, which would mask the problem).
		if (!$this->findDescriptor($sourceDir)) {
			$this->cleanupDir($extractDir);
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Module descriptor not found in downloaded archive', 'backup_path' => $backupPath);
		}

		// Replace module directory. From here on the target IS mutated, so failures
		// trigger a restore from backup.
		$isSelfUpdate = ($module_id === 'dolimodulemanager');
		if ($isUpdate && $isSelfUpdate) {
			// Self-update: DMM cannot delete/rename its own running directory, so copy
			// in place. Stale files may linger, but a self-update rarely drops files and
			// swapping the live module mid-request would fatal.
			$copyResult = $this->recursiveCopy($sourceDir, $targetDir);
			$this->cleanupDir($sourceDir);
			if (!$copyResult) {
				$detail = $this->error ?: 'unknown error';
				$detail .= ' | dest_writable='.var_export(is_writable($targetDir), true);
				if ($backupPath) {
					$this->restoreFromBackup($module_id, $backupPath);
				}
				$this->cleanupDir($extractDir);
				@unlink($tarGzPath);
				return array('success' => false, 'message' => 'Failed to copy module files to '.$targetDir.' ('.$detail.')', 'backup_path' => $backupPath);
			}
		} elseif ($isUpdate) {
			// Regular update: atomic swap so files removed upstream don't linger, and a
			// failed copy never leaves the module half-written. Stage, then rename-swap.
			$stagingDir = $targetDir.'.dmmnew';
			$oldDir = $targetDir.'.dmmold';
			if (is_dir($stagingDir)) {
				$this->cleanupDir($stagingDir);
			}
			if (is_dir($oldDir)) {
				$this->cleanupDir($oldDir);
			}
			if (!@rename($sourceDir, $stagingDir) && !$this->recursiveCopy($sourceDir, $stagingDir)) {
				$this->cleanupDir($stagingDir);
				$this->cleanupDir($sourceDir);
				$this->cleanupDir($extractDir);
				@unlink($tarGzPath);
				return array('success' => false, 'message' => 'Failed to stage update in '.$stagingDir, 'backup_path' => $backupPath);
			}
			$this->cleanupDir($sourceDir);
			if (!@rename($targetDir, $oldDir)) {
				$this->cleanupDir($stagingDir);
				$this->cleanupDir($extractDir);
				@unlink($tarGzPath);
				return array('success' => false, 'message' => 'Failed to move current module aside: '.$targetDir, 'backup_path' => $backupPath);
			}
			if (!@rename($stagingDir, $targetDir)) {
				// Promote failed — put the original module back.
				@rename($oldDir, $targetDir);
				$this->cleanupDir($stagingDir);
				$this->cleanupDir($extractDir);
				@unlink($tarGzPath);
				return array('success' => false, 'message' => 'Failed to promote updated module into '.$targetDir, 'backup_path' => $backupPath);
			}
			$this->cleanupDir($oldDir);
		} else {
			// Fresh install — move into place, fall back to copy across filesystems.
			if (!@rename($sourceDir, $targetDir)) {
				@mkdir($targetDir, 0755, true);
				if (!$this->recursiveCopy($sourceDir, $targetDir)) {
					$this->cleanupDir($targetDir);
					$this->cleanupDir($sourceDir);
					$this->cleanupDir($extractDir);
					@unlink($tarGzPath);
					return array('success' => false, 'message' => 'Failed to copy module files to '.$targetDir, 'backup_path' => $backupPath);
				}
				$this->cleanupDir($sourceDir);
			}
		}

		// Verify: descriptor is present in the deployed directory.
		if (!$this->findDescriptor($targetDir)) {
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			} else {
				dol_delete_dir_recursive($targetDir);
			}
			$this->cleanupDir($extractDir);
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Module descriptor not found after deployment', 'backup_path' => $backupPath);
		}

		// Cleanup temp files
		$this->cleanupDir($extractDir);
		@unlink($tarGzPath);
		$tarPath = preg_replace('/\.gz$/', '', $tarGzPath);
		if (file_exists($tarPath)) {
			@unlink($tarPath);
		}

		// Update registry if standalone. When we installed a branch rather than a
		// tag, store the resolved commit SHA so future checks compare against an
		// immutable identifier.
		//
		// This covers two cases that must agree. The dev channel is the explicit
		// one. The other is the branch-HEAD fallback for a repo that publishes no
		// releases: checkUpdate() reports "dev:{sha12}" there, so recording the
		// branch *name* (ltrim($tag,'vV') == "main") left the two able to never
		// match, and the module advertised an update on every single check.
		$branchInstall = ($channel === 'dev');
		if (!$branchInstall && $modRow && !empty($modRow->branch) && $tag === $modRow->branch) {
			$branchInstall = true;
		}

		if ($branchInstall) {
			// A source that publishes a version says what it just installed better
			// than the commit it came from: the community index states 1.0.3 and the
			// module on disk agrees, so recording "dev:{sha}" would show the card an
			// SHA as the installed version next to a semver as the latest. Only fall
			// back to the SHA when there is no such statement to record.
			//
			// The freshly written files are the more direct evidence of the two, so
			// they win over the index where both speak: the branch may have moved
			// past the version the index still advertises.
			$published = $this->publishedBranchVersion($modRow);
			$newVersion = $published !== null
				? ($this->getInstalledVersion($module_id) ?: $published)
				: null;
			if ($newVersion === null) {
				// One prefix for both cases. A second one ("branch:") would have to be
				// taught to every comparison that already understands "dev:", and the
				// first one missed would resurrect the perpetual-update bug.
				$sha = $this->fetchBranchSha($owner, $repoName, $tag, $token, $gitHost, $gitBaseUrl);
				$newVersion = $sha ? 'dev:'.substr($sha, 0, 12) : 'dev:'.$tag;
			}
		} else {
			$newVersion = ltrim($tag, 'vV');
		}
		if ($this->standalone) {
			$this->updateModuleRegistry($module_id, $newVersion);
		}

		$action = $isUpdate ? 'updated' : 'installed';
		return array('success' => true, 'message' => 'Module '.$module_id.' '.$action.' to version '.$newVersion, 'backup_path' => $backupPath);
	}

	/**
	 * Install or update a module from a free DoliStore product ZIP.
	 *
	 * @param  string $module_id      Module identifier (sanitized)
	 * @param  int    $dolistore_id   DoliStore product id
	 * @return array                  ['success' => bool, 'message' => string, 'backup_path' => ?string]
	 */
	public function installFromDolistoreZip($module_id, $dolistore_id)
	{
		return $this->installFromDolistoreArchive($module_id, $dolistore_id, null);
	}

	/**
	 * Install or update a module from a DoliStore archive.
	 *
	 * Mirrors installOrUpdate() but pulls the archive from
	 * www.dolistore.com/_service_download.php instead of a Git tarball.
	 * The DoliStore ZIP layout is "{module}/..." or "htdocs/{module}/..." —
	 * findModuleRoot() handles both via case 1/2/3 once we treat the ZIP
	 * top-level as the wrapper directory.
	 *
	 * Free and purchased products differ only in which endpoint yields the bytes:
	 * everything after the download — unzip, htdocs peeling, descriptor-wins rename,
	 * backup and rollback, registry update — is the same, and used to be two
	 * near-identical copies that could drift apart.
	 *
	 * @param  string      $module_id    Module identifier (sanitized)
	 * @param  int         $dolistore_id DoliStore product id
	 * @param  string|null $wrapper_url  Authenticated wrapper.php URL for a purchased
	 *                                   product; null selects the anonymous free endpoint
	 * @return array                     ['success' => bool, 'message' => string, 'backup_path' => ?string]
	 */
	private function installFromDolistoreArchive($module_id, $dolistore_id, $wrapper_url = null)
	{
		$isPaid = ($wrapper_url !== null && $wrapper_url !== '');
		$tag = $isPaid ? 'dolistore-paid-' : 'dolistore-';
		$slug = $isPaid ? 'dmm_dolistore_paid_' : 'dmm_dolistore_';

		$module_id = $this->sanitizeModuleId($module_id);
		if ($module_id === false) {
			return array('success' => false, 'message' => 'Invalid module ID', 'backup_path' => null);
		}
		$dolistore_id = (int) $dolistore_id;
		if ($dolistore_id <= 0) {
			return array('success' => false, 'message' => 'Invalid DoliStore id', 'backup_path' => null);
		}

		$customDir = DOL_DOCUMENT_ROOT.'/custom/';
		$targetDir = $customDir.$module_id;
		if (!is_writable($customDir)) {
			return array('success' => false, 'message' => 'Cannot write to '.$customDir, 'backup_path' => null);
		}
		if ($this->isCoreModule($module_id)) {
			return array('success' => false, 'message' => 'Cannot overwrite core Dolibarr module: '.$module_id, 'backup_path' => null);
		}

		$isUpdate = is_dir($targetDir);
		$backupPath = null;

		$tempDir = $this->getTempDir();
		$zipPath = $tempDir.'/'.$slug.$dolistore_id.'_'.uniqid().'.zip';

		if ($isPaid) {
			dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');
			$ses = new DMMDolistoreSession($this->db);
			$dl = $ses->downloadPurchaseZip($wrapper_url, $zipPath);
		} else {
			dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
			$ds = new DMMDolistoreClient();
			$dl = $ds->downloadFreeZip($dolistore_id, $zipPath);
		}
		if (!$dl['ok']) {
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			$what = $isPaid ? 'DoliStore purchase download failed: ' : 'DoliStore download failed: ';
			return array('success' => false, 'message' => $what.$dl['error'], 'backup_path' => $backupPath);
		}

		$extractDir = $tempDir.'/'.$slug.'extract_'.uniqid();
		@dol_mkdir($extractDir);
		$un = dol_uncompress($zipPath, $extractDir);
		if (!empty($un['error'])) {
			@unlink($zipPath);
			$this->cleanupDir($extractDir);
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			return array('success' => false, 'message' => 'Unzip failed: '.$un['error'], 'backup_path' => $backupPath);
		}

		// DoliStore ZIPs unpack one of two ways:
		//   (A) {module}/core/modules/modXxx.class.php   -> use extractDir as wrapper
		//   (B) {module}/htdocs/{module}/core/...         -> peel htdocs first
		$sourceDir = $this->findModuleRoot($extractDir, $module_id, null);
		if ($sourceDir === false || !$this->findDescriptor($sourceDir)) {
			// Try the htdocs/ peeling fallback used by some publishers.
			$peeled = $this->peelHtdocs($extractDir);
			if ($peeled !== null) {
				$sourceDir = $this->findModuleRoot($peeled, $module_id, null);
			}
		}
		if ($sourceDir === false || !$this->findDescriptor($sourceDir)) {
			@unlink($zipPath);
			$this->cleanupDir($extractDir);
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			return array('success' => false, 'message' => 'Module descriptor not found in DoliStore archive', 'backup_path' => $backupPath);
		}

		// Trust the descriptor over the seed module_id derived from the API label.
		// DoliStore product labels frequently include spaces/accents/dashes that get
		// stripped to gibberish (e.g. "Dolicraft Dashboard - tableau de bord avancé"
		// would land at custom/dolicraftdashboardtableaudebordavanc... while every
		// hook in Dolibarr looks for /custom/dolicraftdashboard/ based on the class
		// name). Always use the descriptor's modXxx class -> "xxx" lowercase id.
		$seedModuleId = $module_id;
		$realModuleId = $this->extractModuleIdFromDescriptor($sourceDir);
		if ($realModuleId !== null && $realModuleId !== $module_id) {
			$module_id = $realModuleId;
			$targetDir = $customDir.$module_id;
			$isUpdate = is_dir($targetDir);
		}

		if ($isUpdate) {
			$permError = $this->checkWritePermissions($targetDir);
			if ($permError !== null) {
				@unlink($zipPath);
				$this->cleanupDir($extractDir);
				return array('success' => false, 'message' => 'Permission denied: '.$permError, 'backup_path' => null);
			}
			$backupResult = $this->createBackup($module_id, $tag.$dolistore_id);
			if (!$backupResult['success']) {
				@unlink($zipPath);
				$this->cleanupDir($extractDir);
				return array('success' => false, 'message' => 'Backup failed: '.$backupResult['message'], 'backup_path' => null);
			}
			$backupPath = $backupResult['backup_path'];
		}

		$deploy = $this->deployModuleDirectory($sourceDir, $targetDir, $isUpdate);
		if (!$deploy['success']) {
			@unlink($zipPath);
			$this->cleanupDir($extractDir);
			return array('success' => false, 'message' => $deploy['message'], 'backup_path' => $backupPath);
		}

		if ($this->standalone && $seedModuleId !== $module_id) {
			$this->renameRegistryRow($seedModuleId, $module_id);
		}

		// Read the descriptor to extract the real version (the API number is unreliable;
		// the descriptor wins).
		$installedVersion = $this->getInstalledVersion($module_id);
		if (empty($installedVersion)) {
			$installedVersion = 'dolistore-'.$dolistore_id;
		}

		@unlink($zipPath);
		$this->cleanupDir($extractDir);

		if ($this->standalone && !$this->updateModuleRegistry($module_id, $installedVersion)) {
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			} else {
				$this->cleanupDir($targetDir);
			}
			return array('success' => false, 'message' => 'Registry update failed; deployed files were rolled back', 'backup_path' => $backupPath);
		}

		$action = $isUpdate ? 'updated' : 'installed';
		$origin = $isPaid ? 'DoliStore purchase' : 'DoliStore';
		return array('success' => true, 'message' => 'Module '.$module_id.' '.$action.' from '.$origin.' (v'.$installedVersion.')', 'backup_path' => $backupPath);
	}

	/**
	 * Install or update a module from a purchased DoliStore product.
	 *
	 * Same pipeline as installFromDolistoreZip() but the ZIP is fetched through
	 * an authenticated DMMDolistoreSession (cookie or email/password). The
	 * wrapper.php URL comes from the order-history scrape and embeds an order
	 * key — DMM never has to know the exact download URL beforehand.
	 *
	 * @param  string $module_id    Seed module identifier (sanitized; corrected from descriptor post-extract)
	 * @param  int    $dolistore_id DoliStore product id (registry tracking)
	 * @param  string $wrapper_url  wrapper.php URL surfaced by the order history table
	 * @return array                ['success' => bool, 'message' => string, 'backup_path' => ?string]
	 */
	public function installFromDolistorePurchase($module_id, $dolistore_id, $wrapper_url)
	{
		// An empty URL would silently fall through to the anonymous free endpoint,
		// which answers "paiedProduct" for exactly the products that reach here.
		// Say what actually went wrong instead.
		if (empty($wrapper_url)) {
			return array('success' => false, 'message' => 'Missing wrapper URL (re-scrape your purchases)', 'backup_path' => null);
		}
		return $this->installFromDolistoreArchive($module_id, $dolistore_id, $wrapper_url);
	}

	/**
	 * Rename a registry row's module_id (used after we discover the canonical
	 * descriptor id mid-install). If the destination id already exists (because
	 * the user re-installed the same product after a manual cleanup), the
	 * orphaned seed row is dropped instead.
	 *
	 * @param  string $oldId
	 * @param  string $newId
	 * @return bool True when no registry is needed or the row was updated
	 */
	private function renameRegistryRow($oldId, $newId)
	{
		if ($oldId === $newId) {
			return;
		}
		$prefix = $this->db->prefix();
		$exists = $this->db->query("SELECT rowid FROM ".$prefix."dmm_module WHERE module_id = '".$this->db->escape($newId)."'");
		if ($exists && $this->db->num_rows($exists) > 0) {
			// Drop the seed row (created with the wrong module_id) and keep the existing
			// canonical one. Backups linked to the seed row, if any, get unlinked first.
			$this->db->query("UPDATE ".$prefix."dmm_backup SET fk_dmm_module = NULL WHERE fk_dmm_module IN (SELECT rowid FROM ".$prefix."dmm_module WHERE module_id = '".$this->db->escape($oldId)."')");
			$this->db->query("DELETE FROM ".$prefix."dmm_module WHERE module_id = '".$this->db->escape($oldId)."'");
			return;
		}
		$this->db->query("UPDATE ".$prefix."dmm_module SET module_id = '".$this->db->escape($newId)."' WHERE module_id = '".$this->db->escape($oldId)."'");
	}

	/**
	 * Extract the canonical module_id from the descriptor at $moduleDir.
	 *
	 * Convention: descriptor file is core/modules/mod{Name}.class.php and the
	 * Dolibarr-wide module id is strtolower("{Name}"). This is what conf->module
	 * keys, hook paths, and language file paths all use.
	 *
	 * @param  string      $moduleDir Path containing core/modules/mod*.class.php
	 * @return string|null            Lowercased module id, or null if not findable
	 */
	/**
	 * Descriptor class name for a module on disk, case intact ("modChangeTiers").
	 *
	 * Dolibarr's own enable/disable links take this exact name as their `value`
	 * parameter, and it is case-sensitive — extractModuleIdFromDescriptor()
	 * lowercases, which is right for a directory id and wrong here.
	 *
	 * @param  string      $module_id Directory name under custom/
	 * @return string|null            e.g. "modChangeTiers", or null if not found
	 */
	public function getDescriptorClass($module_id)
	{
		$dir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id.'/core/modules';
		if (!is_dir($dir)) {
			return null;
		}
		$files = @scandir($dir);
		if (!is_array($files)) {
			return null;
		}
		foreach ($files as $f) {
			if (preg_match('/^(mod[A-Za-z0-9_]+)\.class\.php$/', $f, $m)) {
				return $m[1];
			}
		}
		return null;
	}

	private function extractModuleIdFromDescriptor($moduleDir)
	{
		$dir = $moduleDir.'/core/modules';
		if (!is_dir($dir)) {
			return null;
		}
		$files = @scandir($dir);
		if (!is_array($files)) {
			return null;
		}
		foreach ($files as $f) {
			if (preg_match('/^mod([A-Za-z0-9_]+)\.class\.php$/', $f, $m)) {
				return strtolower($m[1]);
			}
		}
		return null;
	}

	/**
	 * If $extractDir/<wrapper>/htdocs exists, return the htdocs path.
	 * Used by installFromDolistoreZip to handle the "htdocs/{module}/" layout.
	 *
	 * @param  string $extractDir
	 * @return string|null
	 */
	private function peelHtdocs($extractDir)
	{
		$entries = @scandir($extractDir);
		if (!is_array($entries)) {
			return null;
		}
		foreach ($entries as $e) {
			if ($e === '.' || $e === '..') {
				continue;
			}
			$wrapper = $extractDir.'/'.$e;
			if (is_dir($wrapper.'/htdocs')) {
				return $wrapper.'/htdocs';
			}
		}
		return null;
	}

	/**
	 * Restore a module from a backup.
	 *
	 * @param  string $module_id   Module identifier
	 * @param  string $backup_path Path to backup directory
	 * @return array               Result: ['success' => bool, 'message' => string]
	 */
	public function rollback($module_id, $backup_path)
	{
		$module_id = $this->sanitizeModuleId($module_id);
		if ($module_id === false) {
			return array('success' => false, 'message' => 'Invalid module ID');
		}

		return $this->restoreFromBackup($module_id, $backup_path);
	}

	/**
	 * List all modules accessible via the given token.
	 *
	 * @param  string|null $token GitHub token
	 * @return array              List of module metadata
	 */
	public function listAvailableModules($token = null, &$scanReport = null)
	{
		$modules = array();
		$scanReport = array('repos_visible' => array(), 'repos_dmm' => array(), 'repos_other' => array());

		if (empty($token)) {
			return $modules;
		}

		// Get repos accessible by token
		$page = 1;
		$repos = array();
		do {
			$result = $this->githubApiCall('/user/repos?per_page=100&page='.$page, $token);
			if ($result === null || $result['code'] !== 200) {
				break;
			}
			$pageRepos = json_decode($result['body'], true);
			if (!is_array($pageRepos) || empty($pageRepos)) {
				break;
			}
			$repos = array_merge($repos, $pageRepos);
			$page++;
		} while (count($pageRepos) === 100);

		// Check each repo for dmm.json and dmmhub.json.
		//
		// A token scan is the widest sweep DMM does — one lookup per repository the
		// token can see, and historically a second one for every repo without a
		// manifest. Both stages now go through the cached, concurrent fetcher, so a
		// repeat scan mostly costs 304s (which GitHub does not bill against the
		// rate limit) rather than a fresh round trip per repo.
		$scanReport['repos_hub'] = array();

		$manifestReqs = array();
		foreach ($repos as $repoData) {
			$fullName = $repoData['full_name'] ?? '';
			if (empty($fullName) || strpos($fullName, '/') === false) {
				continue;
			}
			$scanReport['repos_visible'][] = $fullName;
			list($lsOwner, $lsRepo) = explode('/', $fullName, 2);
			$manifestReqs[$fullName] = array('owner' => $lsOwner, 'repo' => $lsRepo, 'token' => $token);
		}

		$manifests = $this->fetchManifestsBatch($manifestReqs);

		// Repos with no dmm.json are the only ones that can still be a hub.
		$hubCandidates = array();
		foreach ($manifestReqs as $fullName => $req) {
			$manifest = $manifests[$fullName] ?? null;
			if ($manifest !== null) {
				$manifest['github_repo'] = $fullName;
				$modules[] = $manifest;
				$scanReport['repos_dmm'][] = $fullName;
				continue;
			}
			$hubCandidates[$fullName] = $req;
		}

		foreach ($this->probeFilesBatch($hubCandidates, 'dmmhub.json') as $fullName => $isHub) {
			if ($isHub) {
				$scanReport['repos_hub'][] = $fullName;
			} else {
				$scanReport['repos_other'][] = $fullName;
			}
		}

		return $modules;
	}

	/**
	 * Test whether a file exists in each of several repositories, concurrently.
	 *
	 * Only the status code matters, so these are HEAD-style existence probes
	 * rather than content fetches — used to spot dmmhub.json across every repo a
	 * token can reach without paying a serial round trip apiece.
	 *
	 * @param  array  $requests Keyed list of ['owner'=>,'repo'=>,'token'=>]
	 * @param  string $path     Repository-relative file path
	 * @return array            Same keys => bool
	 */
	private function probeFilesBatch(array $requests, $path)
	{
		$out = array();
		foreach ($requests as $key => $req) {
			$out[$key] = false;
		}
		if (empty($requests)) {
			return $out;
		}

		// Same freshness window as the manifest cache: whether a repo publishes a
		// dmmhub.json changes about as rarely, and this probe runs once per repo
		// the token can see — the widest sweep DMM makes.
		$pending = $requests;
		$probeTtl = dol_now() - self::MANIFEST_CACHE_TTL;
		if (function_exists('dmm_get_setting')) {
			foreach ($requests as $key => $req) {
				$ckey = 'probe_cache_'.md5(strtolower($req['owner'].'/'.$req['repo'].'/'.$path));
				$raw = dmm_get_setting($ckey, null);
				$entry = $raw ? json_decode($raw, true) : null;
				if (is_array($entry) && isset($entry['found'], $entry['ts']) && $entry['ts'] > $probeTtl) {
					$out[$key] = (bool) $entry['found'];
					unset($pending[$key]);
				}
			}
		}
		if (empty($pending)) {
			return $out;
		}

		if (!function_exists('curl_multi_init')) {
			foreach ($pending as $key => $req) {
				$probe = $this->githubApiCall('/repos/'.$req['owner'].'/'.$req['repo'].'/contents/'.$path, $req['token'] ?? null);
				$out[$key] = ($probe !== null && $probe['code'] === 200);
				$this->writeProbeCache($req['owner'], $req['repo'], $path, $out[$key]);
			}
			return $out;
		}

		foreach (array_chunk($pending, 8, true) as $chunk) {
			$mh = curl_multi_init();
			$handles = array();
			foreach ($chunk as $key => $req) {
				$headers = array('User-Agent: DMM/1.0', 'Accept: application/vnd.github+json');
				if (!empty($req['token'])) {
					$headers[] = 'Authorization: Bearer '.$req['token'];
				}
				$ch = curl_init('https://api.github.com/repos/'.$req['owner'].'/'.$req['repo'].'/contents/'.$path);
				curl_setopt_array($ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_NOBODY => true,
				));
				curl_multi_add_handle($mh, $ch);
				$handles[$key] = $ch;
			}

			$running = null;
			do {
				curl_multi_exec($mh, $running);
				curl_multi_select($mh, 1.0);
			} while ($running > 0);

			foreach ($handles as $key => $ch) {
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$out[$key] = ($code === 200);
				// Only a definite answer is worth remembering: a 403 quota wall or a
				// transport error says nothing about whether the file is there.
				if ($code === 200 || $code === 404) {
					$this->writeProbeCache($chunk[$key]['owner'], $chunk[$key]['repo'], $path, $out[$key]);
				}
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);
		}

		return $out;
	}

	/**
	 * Remember whether a file exists in a repository.
	 *
	 * @param  string $owner Repo owner
	 * @param  string $repo  Repo name
	 * @param  string $path  Repository-relative file path
	 * @param  bool   $found Whether it was there
	 * @return void
	 */
	private function writeProbeCache($owner, $repo, $path, $found)
	{
		if (!function_exists('dmm_set_setting')) {
			return;
		}
		dmm_set_setting('probe_cache_'.md5(strtolower($owner.'/'.$repo.'/'.$path)), json_encode(array(
			'found' => $found ? 1 : 0,
			'ts' => dol_now(),
		)));
	}

	/**
	 * Discover and register all DMM-compatible modules accessible via a token.
	 * Scans repos for dmm.json, registers new ones in llx_dmm_module.
	 *
	 * @param  int    $tokenRowId  Token row ID in llx_dmm_token
	 * @param  string $plainToken  Decrypted GitHub token
	 * @return array               ['discovered' => int, 'skipped' => int, 'errors' => string[]]
	 */
	public function discoverModules($tokenRowId, $plainToken, $discoverHubs = true)
	{
		$result = array('discovered' => 0, 'skipped' => 0, 'errors' => array(), 'scan' => array(), 'hubs_found' => array());

		$scanReport = null;
		$modules = $this->listAvailableModules($plainToken, $scanReport);
		$result['scan'] = $scanReport;

		// Auto-register discovered hubs. Opt-in: this walks every repo the token
		// can see and imports each hub it finds, which is far too much work for a
		// routine "refresh". It belongs behind the explicit button in Sources.
		if ($discoverHubs && !empty($scanReport['repos_hub']) && function_exists('dmm_get_hubs') && function_exists('dmm_save_hubs') && function_exists('dmm_hub_identity')) {
			$hubs = dmm_get_hubs();
			// Compare on canonical identity so a hub already present under another URL
			// form (e.g. raw.githubusercontent.com) is not re-added as a duplicate.
			$existingIds = array();
			foreach ($hubs as $h) {
				$existingIds[dmm_hub_identity($h['url'])] = true;
			}

			foreach ($scanReport['repos_hub'] as $hubRepo) {
				$hubUrl = 'https://api.github.com/repos/'.$hubRepo.'/contents/dmmhub.json';
				$identity = dmm_hub_identity($hubUrl);
				if (!isset($existingIds[$identity])) {
					$existingIds[$identity] = true;
					$hubs[] = array('url' => $hubUrl, 'enabled' => 0);
					$result['hubs_found'][] = $hubRepo;
					// Import modules from this hub
					$hubReport = $this->importFromHub($hubUrl);
					$result['discovered'] += $hubReport['registered'];
					$result['skipped'] += $hubReport['skipped'];
				}
			}
			if (!empty($result['hubs_found'])) {
				dmm_save_hubs($hubs);
			}
		}

		if (empty($modules) && empty($result['hubs_found'])) {
			return $result;
		}

		if (!$this->standalone) {
			$result['errors'][] = 'Discovery requires standalone mode (DMM tables)';
			return $result;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		global $user;

		foreach ($modules as $manifest) {
			$module_id = $manifest['module_id'] ?? '';
			$github_repo = $manifest['github_repo'] ?? '';

			if (empty($module_id) || empty($github_repo)) {
				continue;
			}

			// Check if already registered (by module_id OR by github_repo). A row
			// that predates this token (local scan, hub import) has no credential:
			// attach this one, otherwise a private repo keeps failing with 404 on
			// every check even though a working token now exists.
			$existing = new DMMModule($this->db);
			$found = ($existing->fetch(0, $module_id) > 0);
			if (!$found) {
				$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($github_repo)."'";
				$resCheck = $this->db->query($sqlCheck);
				if ($resCheck && $this->db->num_rows($resCheck) > 0) {
					$objCheck = $this->db->fetch_object($resCheck);
					$found = ($existing->fetch((int) $objCheck->rowid) > 0);
				}
			}
			if ($found) {
				if (empty($existing->fk_dmm_token) && ($existing->git_host ?? 'github') === 'github') {
					$existing->fk_dmm_token = $tokenRowId;
					$existing->cache_last_error = null;
					$existing->update($user);
					$result['linked'] = ($result['linked'] ?? 0) + 1;
				} else {
					$result['skipped']++;
				}
				continue;
			}

			// Register new module
			$mod = new DMMModule($this->db);
			$mod->module_id = $module_id;
			$mod->github_repo = $github_repo;
			$mod->fk_dmm_token = $tokenRowId;
			$mod->name = $manifest['name'] ?? null;
			$mod->description = $manifest['description'] ?? null;
			$mod->author = $manifest['author'] ?? null;
			$mod->license = $manifest['license'] ?? null;
			$mod->url = $manifest['url'] ?? null;

			// Same rule as the hub import: a token scan is discovery, not
			// registration. What it finds is browsable under "Add a module";
			// only modules present on disk earn a row.
			$localDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (!is_dir($localDir) || !is_dir($localDir.'/core/modules')) {
				$result['skipped']++;
				continue;
			}

			$mod->installed = 1;
			$descFiles = glob($localDir.'/core/modules/mod*.class.php');
			if (!empty($descFiles)) {
				$content = file_get_contents($descFiles[0]);
				if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $vm)) {
					$mod->installed_version = $vm[1];
				}
			}

			$createResult = $mod->create($user);
			if ($createResult > 0) {
				$result['discovered']++;
			} else {
				$result['errors'][] = 'Failed to register '.$module_id.': '.$mod->error;
			}
		}

		return $result;
	}

	/**
	 * Scan custom/ and collect EVERY source that could back each module, without
	 * writing anything: the caller renders a per-module choice table and the user
	 * picks. Registration happens later, through registerScannedModule().
	 *
	 * Sources collected per module: 'github' (local dmm.json, else an enabled hub),
	 * 'dolistore_purchase' (matched against the account's DoliStore orders) and
	 * 'dolistore_public' (best-effort name match in the public catalog).
	 *
	 * Preselection order is GitHub first, then a DoliStore purchase, then the public
	 * catalog: a Git-backed module stays Git-backed (real releases, real changelog),
	 * and an exact purchase id always beats the fuzzy name match.
	 *
	 * @param  array $purchases Products from DMMDolistoreSession::fetchPurchases()
	 *                          (each with id/name/zip_url). Pass array() to skip.
	 * @return array{
	 *   candidates: array<int,array{module_id:string,version:?string,sources:array<string,array>,preselect:?string}>,
	 *   matched_existing: array<int,string>,
	 *   skipped_core: array<int,string>,
	 *   errors: array<int,string>
	 * }
	 */
	/**
	 * Every real module directory under custom/, whatever the registry knows.
	 *
	 * The registry answers "what has DMM been told about", which is not the same
	 * question as "what is installed on this Dolibarr" — a module dropped in by FTP
	 * or installed before DMM is absent from it entirely. Listing the disk is what
	 * lets the UI show those, marked as unmanaged.
	 *
	 * Deliberately filesystem-only: no hub fetch, no catalog download, no purchase
	 * scrape. That is what makes it safe to call on every page load, unlike
	 * scanLocalCandidates(), which resolves sources over the network.
	 *
	 * @return array<string,array{module_id:string,version:?string}> keyed by module_id
	 */
	public function listInstalledOnDisk()
	{
		$out = array();

		$dirs = glob(DOL_DOCUMENT_ROOT.'/custom/*', GLOB_ONLYDIR);
		if ($dirs === false) {
			return $out;
		}

		foreach ($dirs as $dir) {
			$module_id = basename($dir);

			// DMM is added to the list from its registry row instead (see the
			// dashboard), so that it keeps its source and version columns rather than
			// showing up as an unmanaged directory. A core module living under custom/
			// is not a third-party install either.
			if ($module_id === self::SELF_MODULE_ID) {
				continue;
			}
			if (function_exists('dmm_is_core_module') && dmm_is_core_module($module_id)) {
				continue;
			}
			// No descriptor means it is not a Dolibarr module (docs, data, leftovers).
			if (!$this->findDescriptor($dir)) {
				continue;
			}

			$out[$module_id] = array(
				'module_id' => $module_id,
				'version' => $this->getInstalledVersion($module_id),
			);
		}

		ksort($out);
		return $out;
	}

	public function scanLocalCandidates(array $purchases = array())
	{
		$result = array(
			'candidates' => array(),
			'matched_existing' => array(),
			'skipped_core' => array(),
			'errors' => array(),
		);

		if (!$this->standalone) {
			$result['errors'][] = 'Local scan requires standalone mode (DMM tables)';
			return $result;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

		$customDir = DOL_DOCUMENT_ROOT.'/custom';
		$dirs = glob($customDir.'/*', GLOB_ONLYDIR);
		if ($dirs === false) {
			$result['errors'][] = 'Cannot read '.$customDir;
			return $result;
		}

		// Both catalogs are fetched at most once for the whole scan, and only when a
		// module actually needs them.
		$hubModules = null;
		$dolistoreProducts = null;

		foreach ($dirs as $dir) {
			$module_id = basename($dir);

			if ($module_id === 'dolimodulemanager') {
				continue;
			}
			if (function_exists('dmm_is_core_module') && dmm_is_core_module($module_id)) {
				$result['skipped_core'][] = $module_id;
				continue;
			}
			if (!$this->findDescriptor($dir)) {
				continue;
			}

			$existing = new DMMModule($this->db);
			if ($existing->fetch(0, $module_id) > 0) {
				$result['matched_existing'][] = $module_id;
				continue;
			}

			$version = $this->getInstalledVersion($module_id);
			$sources = array();

			$localManifest = null;
			$dmmJsonPath = $dir.'/dmm.json';
			if (is_file($dmmJsonPath)) {
				$localManifest = json_decode((string) @file_get_contents($dmmJsonPath), true);
			}

			// --- GitHub/GitLab, from a local dmm.json ---
			$repoSpec = null;
			if (is_array($localManifest)) {
				if (!empty($localManifest['repository'])) {
					$repoSpec = (string) $localManifest['repository'];
				} elseif (!empty($localManifest['url']) && preg_match('#^https?://github\.com/[^/]+/[^/]+#i', (string) $localManifest['url'])) {
					$repoSpec = (string) $localManifest['url'];
				}
			}
			if ($repoSpec !== null) {
				$git = $this->parseRepoSpec($repoSpec);
				$sources['github'] = array(
					'github_repo' => $git['repo'],
					'git_host' => $git['git_host'],
					'git_base_url' => $git['git_base_url'],
					'name' => $localManifest['name'] ?? null,
					'description' => $localManifest['description'] ?? null,
					'author' => $localManifest['author'] ?? null,
					'license' => $localManifest['license'] ?? null,
					'url' => $localManifest['url'] ?? null,
					'origin' => 'dmm.json',
				);
			}

			// --- GitHub/GitLab, from an enabled hub (only if dmm.json gave nothing) ---
			if (!isset($sources['github']) && function_exists('dmm_get_hubs')) {
				if ($hubModules === null) {
					$hubModules = $this->collectHubModules();
				}
				if (isset($hubModules[$module_id]) && !empty($hubModules[$module_id]['repo'])) {
					$entry = $hubModules[$module_id];
					$sources['github'] = array(
						'github_repo' => $entry['repo'],
						'git_host' => $entry['git_host'] ?? 'github',
						'git_base_url' => $entry['git_base_url'] ?? null,
						'name' => $entry['name'] ?? null,
						'origin' => 'hub',
					);
				}
			}

			// --- DoliStore purchase (exact product id from the order history) ---
			$purchase = $this->matchDolistorePurchase($module_id, $localManifest, $purchases);
			if ($purchase !== null) {
				$sources['dolistore_purchase'] = array(
					'source' => 'dolistore',
					'dolistore_id' => (int) $purchase['id'],
					'github_repo' => 'dolistore:'.((int) $purchase['id']),
					'name' => $purchase['name'] ?? null,
					'origin' => 'purchase',
				);
			}

			// --- DoliStore public catalog (fuzzy name match, last resort) ---
			// Opportunistic: this is the least reliable source, so it is not worth
			// downloading the whole catalog for. When the cache is cold we simply skip
			// it — the row then offers the id field and the DoliStore search link.
			if ($dolistoreProducts === null) {
				$dolistoreProducts = $this->isDolistoreCatalogCached() ? $this->loadDolistoreCatalog() : array();
			}
			$match = $this->matchDolistoreProduct($module_id, $localManifest, $dolistoreProducts);
			if ($match !== null) {
				$publicId = (int) $match['id'];
				// Skip when it is the very product we already matched as a purchase.
				if (!isset($sources['dolistore_purchase']) || $sources['dolistore_purchase']['dolistore_id'] !== $publicId) {
					$sources['dolistore_public'] = array(
						'source' => 'dolistore',
						'dolistore_id' => $publicId,
						'github_repo' => 'dolistore:'.$publicId,
						'name' => $match['label'] ?? null,
						'origin' => 'catalog',
					);
				}
			}

			// GitHub wins, then an exact purchase, then the fuzzy catalog match.
			$preselect = null;
			foreach (array('github', 'dolistore_purchase', 'dolistore_public') as $key) {
				if (isset($sources[$key])) {
					$preselect = $key;
					break;
				}
			}

			$result['candidates'][] = array(
				'module_id' => $module_id,
				'version' => $version,
				'sources' => $sources,
				'preselect' => $preselect,
			);
		}

		return $result;
	}

	/**
	 * Register one scanned module against the source the user picked in the scan
	 * table. Applies the same duplicate guards as scanLocalModules() so a product
	 * already registered under a seed id is never inserted twice.
	 *
	 * @param  string      $module_id   Directory name under custom/
	 * @param  array       $source      Field map (github_repo, git_host, dolistore_id, ...)
	 * @return array{ok:bool,error:?string}
	 */
	/**
	 * Repoint an already-registered module at a different source.
	 *
	 * registerScannedModule() refuses a module_id it already knows, which is right
	 * for adoption but wrong for correction: a module whose source was guessed, or
	 * whose repo moved, needs the row rewritten rather than a second one created.
	 *
	 * The cached version data is dropped along with it — it describes releases of
	 * the old source and would otherwise offer an update from somewhere the module
	 * no longer comes from.
	 *
	 * @param  int   $rowid  Registry row id
	 * @param  array $source github_repo, git_host, git_base_url, source, dolistore_id
	 * @return array{ok:bool,error:?string}
	 */
	public function changeModuleSource($rowid, array $source)
	{
		global $user;

		if (!$this->standalone) {
			return array('ok' => false, 'error' => 'Requires standalone mode (DMM tables)');
		}
		if (empty($source['github_repo'])) {
			return array('ok' => false, 'error' => 'No source given');
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

		$mod = new DMMModule($this->db);
		if ($mod->fetch((int) $rowid) <= 0) {
			return array('ok' => false, 'error' => 'Module not found');
		}

		// Another row already pointing at this source would collide on the unique
		// (module_id, github_repo) key and leave two rows tracking one thing.
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dmm_module";
		$sql .= " WHERE github_repo = '".$this->db->escape($source['github_repo'])."'";
		$sql .= " AND rowid <> ".((int) $rowid);
		$res = $this->db->query($sql);
		if ($res && $this->db->num_rows($res) > 0) {
			return array('ok' => false, 'error' => 'Another module already uses this source');
		}
		if (!empty($source['dolistore_id'])) {
			$sqlDs = "SELECT rowid FROM ".$this->db->prefix()."dmm_module";
			$sqlDs .= " WHERE dolistore_id = ".((int) $source['dolistore_id']);
			$sqlDs .= " AND rowid <> ".((int) $rowid);
			$resDs = $this->db->query($sqlDs);
			if ($resDs && $this->db->num_rows($resDs) > 0) {
				return array('ok' => false, 'error' => 'Another module already uses this DoliStore product');
			}
		}

		$mod->github_repo = $source['github_repo'];
		$mod->git_host = $source['git_host'] ?? 'github';
		$mod->git_base_url = $source['git_base_url'] ?? null;
		$mod->source = $source['source'] ?? null;
		$mod->dolistore_id = $source['dolistore_id'] ?? null;

		if ($mod->update($user) <= 0) {
			return array('ok' => false, 'error' => $mod->error ?: 'update failed');
		}

		// Stale cache now describes the previous source.
		$mod->invalidateCache();

		return array('ok' => true, 'error' => null);
	}

	public function registerScannedModule($module_id, array $source)
	{
		global $user;

		if (!$this->standalone) {
			return array('ok' => false, 'error' => 'Local scan requires standalone mode (DMM tables)');
		}
		if (empty($source['github_repo'])) {
			return array('ok' => false, 'error' => 'No source selected for '.$module_id);
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

		$existing = new DMMModule($this->db);
		if ($existing->fetch(0, $module_id) > 0) {
			return array('ok' => false, 'error' => $module_id.' is already registered');
		}
		if (!empty($source['dolistore_id'])) {
			$dup = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE dolistore_id = ".((int) $source['dolistore_id']));
			if ($dup && $this->db->num_rows($dup) > 0) {
				return array('ok' => false, 'error' => 'DoliStore product already registered for '.$module_id);
			}
		}
		$dupRepo = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($source['github_repo'])."'");
		if ($dupRepo && $this->db->num_rows($dupRepo) > 0) {
			return array('ok' => false, 'error' => 'Source already registered for '.$module_id);
		}

		$mod = new DMMModule($this->db);
		$mod->module_id = $module_id;
		$mod->github_repo = $source['github_repo'];
		$mod->fk_dmm_token = null;
		$mod->installed = 1;
		$mod->installed_version = $this->getInstalledVersion($module_id);
		$mod->name = $source['name'] ?? $module_id;
		$mod->description = $source['description'] ?? null;
		$mod->author = $source['author'] ?? null;
		$mod->license = $source['license'] ?? null;
		$mod->url = $source['url'] ?? null;
		$mod->git_host = $source['git_host'] ?? 'github';
		$mod->git_base_url = $source['git_base_url'] ?? null;
		$mod->source = $source['source'] ?? null;
		$mod->dolistore_id = $source['dolistore_id'] ?? null;

		if ($mod->create($user) > 0) {
			return array('ok' => true, 'error' => null);
		}
		return array('ok' => false, 'error' => 'Failed to register '.$module_id.': '.$mod->error);
	}

	/**
	 * Give DMM its own registry row, once.
	 *
	 * DMM used to learn about itself the long way round: the default hub lists
	 * nikube/DMM, so the first-run import created the row as a side effect. That
	 * made the module's own presence in its own dashboard depend on a network call
	 * — with no hub configured, an unreachable one, or a first run that imported
	 * nothing, DMM was installed, enabled, and invisible in the list it draws.
	 *
	 * Nothing about that row needs the network: the module is on disk, its version
	 * is readable there, and its repository is a constant. Writing it locally also
	 * makes self-update work off the hub, since the update path is driven by the
	 * registry row rather than by the catalogue.
	 *
	 * Idempotent by way of registerScannedModule(), which refuses a module_id or a
	 * source it already knows — a user who repointed DMM at a fork keeps their row.
	 *
	 * @return bool True when a row was created by this call
	 */
	public function ensureSelfRegistered()
	{
		if (!$this->standalone) {
			return false;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

		$existing = new DMMModule($this->db);
		if ($existing->fetch(0, self::SELF_MODULE_ID) > 0) {
			return false;
		}

		$result = $this->registerScannedModule(self::SELF_MODULE_ID, array(
			'github_repo' => self::SELF_REPO,
			'name' => 'DoliModuleManager',
			'author' => 'Nicolas - AnatoleConseil.com',
			'license' => 'GPL-3.0-or-later',
			'git_host' => 'github',
			'source' => 'hub',
		));

		return !empty($result['ok']);
	}

	/**
	 * Normalise a repository spec into repo + host + base URL. Accepts "owner/repo",
	 * a github.com URL, or a full URL on another host (treated as a GitLab base).
	 *
	 * @param  string $repoSpec Raw user or manifest input
	 * @return array{repo:string,git_host:string,git_base_url:?string}
	 */
	public function parseRepoSpec($repoSpec)
	{
		$repo = trim((string) $repoSpec);
		$gitHost = 'github';
		$gitBaseUrl = null;

		if (preg_match('#^https?://#i', $repo) && stripos($repo, 'github.com') === false) {
			if (preg_match('#^(https?://[^/]+)/(.+)$#i', $repo, $m)) {
				$gitHost = 'gitlab';
				$gitBaseUrl = $m[1];
				$repo = rtrim($m[2], '/');
			}
		} else {
			$repo = preg_replace('#^https?://github\.com/#i', '', $repo);
			$repo = preg_replace('#\.git$#i', '', rtrim($repo, '/'));
		}

		return array('repo' => $repo, 'git_host' => $gitHost, 'git_base_url' => $gitBaseUrl);
	}

	/**
	 * Match a local module directory against the account's DoliStore purchases.
	 * Purchases carry an exact product id, so a hit here is far more reliable than
	 * the public-catalog name match.
	 *
	 * @param  string     $module_id     Directory name under custom/
	 * @param  array|null $localManifest Parsed dmm.json, when present
	 * @param  array      $purchases     Products from fetchPurchases()
	 * @return array|null                Matching purchase, or null
	 */
	private function matchDolistorePurchase($module_id, $localManifest, array $purchases)
	{
		if (empty($purchases)) {
			return null;
		}
		$normalize = function ($s) {
			return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
		};
		$needles = array($normalize($module_id));
		if (is_array($localManifest) && !empty($localManifest['name'])) {
			$needles[] = $normalize($localManifest['name']);
		}
		$needles = array_filter(array_unique($needles));
		if (empty($needles)) {
			return null;
		}

		foreach ($purchases as $p) {
			if (empty($p['id'])) {
				continue;
			}
			$candidate = $normalize($p['name'] ?? '');
			if ($candidate === '') {
				continue;
			}
			foreach ($needles as $needle) {
				if ($needle !== '' && $needle === $candidate) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Build a module_id => {repo, name, git_host, git_base_url} map from all enabled
	 * hubs. Each hub is fetched once. Helper for scanLocalModules().
	 *
	 * @return array<string,array>
	 */
	private function collectHubModules()
	{
		$map = array();
		if (!function_exists('dmm_get_hubs')) {
			return $map;
		}
		foreach (dmm_get_hubs() as $hub) {
			if (empty($hub['enabled'])) {
				continue;
			}
			$data = $this->fetchHub($hub['url']);
			if (!is_array($data) || empty($data['modules'])) {
				continue;
			}
			foreach ($data['modules'] as $entry) {
				$mid = $entry['module_id'] ?? null;
				if ($mid === null && !empty($entry['repo'])) {
					// Fall back to the repo's last path segment as the module id.
					$mid = basename($entry['repo']);
				}
				if ($mid !== null && !isset($map[$mid])) {
					$map[$mid] = $entry;
				}
			}
		}
		return $map;
	}

	/**
	 * Load the DoliStore product catalog, swallowing any error (best-effort match).
	 *
	 * @return array<int,array>
	 */
	/**
	 * Whether the DoliStore catalog is already on disk, so callers can decide to use
	 * it without paying for a multi-second download.
	 *
	 * @return bool
	 */
	private function isDolistoreCatalogCached()
	{
		dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
		// Constructed exactly like loadDolistoreCatalog() below, so we test the very
		// cache file that would be read.
		$ds = new DMMDolistoreClient();
		return $ds->isCatalogCached();
	}

	private function loadDolistoreCatalog()
	{
		dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
		$ds = new DMMDolistoreClient();
		$products = $ds->getAllProducts();
		return is_array($products) ? $products : array();
	}

	/**
	 * Best-effort match of a local module to a DoliStore product. Compares the
	 * normalised module_id (and manifest name, if any) against product labels/refs.
	 *
	 * @param  string     $module_id
	 * @param  array|null $localManifest
	 * @param  array      $products
	 * @return array|null Matching raw product or null
	 */
	private function matchDolistoreProduct($module_id, $localManifest, array $products)
	{
		$normalize = function ($s) {
			return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
		};
		$needles = array($normalize($module_id));
		if (is_array($localManifest) && !empty($localManifest['name'])) {
			$needles[] = $normalize($localManifest['name']);
		}
		$needles = array_filter(array_unique($needles));
		if (empty($needles)) {
			return null;
		}

		foreach ($products as $p) {
			$candidates = array($normalize($p['label'] ?? ''), $normalize($p['ref'] ?? ''));
			foreach ($needles as $needle) {
				if ($needle !== '' && in_array($needle, $candidates, true)) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Fetch and parse a dmmhub.json file from a URL.
	 *
	 * @param  string      $url   Hub URL (raw HTTP or GitHub API)
	 * @param  string|null $token Optional token for private hubs
	 * @return array|null         Parsed hub data or null on error
	 */
	public function fetchHub($url, $token = null)
	{
		// Validate URL
		if (!preg_match('#^https?://#i', $url)) {
			$this->error = 'Invalid hub URL: must start with https://';
			return null;
		}

		$ch = curl_init($url);
		$headers = array('User-Agent: DMM/1.0', 'Accept: application/json');
		$hasToken = !empty($token);
		if ($hasToken) {
			$headers[] = 'Authorization: Bearer '.$token;
		}
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 15,
			// Never follow redirects while carrying a token: a hub could 30x the
			// Bearer request to an attacker-controlled host and harvest the PAT.
			CURLOPT_FOLLOWLOCATION => !$hasToken,
			CURLOPT_MAXREDIRS => 3,
		));
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $httpCode !== 200) {
			// A private hub on GitHub returns 401/404 without a token. Retry with stored
			// tokens ONLY when the URL points at GitHub — otherwise a hostile hub URL
			// (or a redirect target) would receive every decrypted PAT as a Bearer header.
			if (($httpCode === 404 || $httpCode === 401) && $this->standalone && empty($token) && $this->isGithubHost($url)) {
				dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
				$tokenObj = new DMMToken($this->db);
				$allTokens = $tokenObj->fetchAll(1);
				foreach ($allTokens as $t) {
					$result = $this->fetchHub($url, $t->getDecryptedToken());
					if ($result !== null) {
						return $result;
					}
				}
			}
			$this->error = 'Failed to fetch hub: HTTP '.$httpCode;
			return null;
		}

		// GitHub API returns content in base64
		$data = json_decode($response, true);
		if (isset($data['content']) && isset($data['encoding']) && $data['encoding'] === 'base64') {
			$response = base64_decode($data['content']);
			$data = json_decode($response, true);
		}

		if (!is_array($data) || !isset($data['schema_version']) || !isset($data['modules'])) {
			$this->error = 'Invalid dmmhub.json format';
			return null;
		}

		if ($data['schema_version'] !== '1') {
			$this->error = 'Unsupported hub schema_version: '.$data['schema_version'];
			return null;
		}

		if (!is_array($data['modules']) || count($data['modules']) > 500) {
			$this->error = 'Hub modules list is invalid or exceeds 500 entries';
			return null;
		}

		return $data;
	}

	/**
	 * Import modules from a hub into the local registry.
	 *
	 * @param  string $url Hub URL
	 * @return array       Report: ['hub_name', 'total', 'public', 'private', 'registered', 'matched', 'needs_token', 'skipped', 'errors']
	 */
	public function importFromHub($url)
	{
		$report = array(
			'hub_name' => '', 'total' => 0, 'public' => 0, 'private' => 0,
			'registered' => 0, 'matched' => 0, 'healed' => 0, 'needs_token' => 0, 'skipped' => 0, 'errors' => array(),
		);

		$hub = $this->fetchHub($url);
		if ($hub === null) {
			$report['errors'][] = $this->error;
			// Cache the error for display in hub list
			if (function_exists('dmm_set_setting')) {
				$errorMsg = $this->error;
				if (strpos($errorMsg, 'HTTP 401') !== false || strpos($errorMsg, 'HTTP 404') !== false) {
					$errorMsg = 'No token with access to this hub';
				}
				dmm_set_setting('hub_cache_'.md5($url), json_encode(array(
					'name' => '?',
					'error' => $errorMsg,
				)));
				dmm_set_setting('hub_last_fetch_'.md5($url), gmdate('Y-m-d H:i:s'));
			}
			return $report;
		}

		$report['hub_name'] = $hub['name'] ?? 'Unknown hub';
		$report['total'] = count($hub['modules']);

		if (!$this->standalone) {
			$report['errors'][] = 'Hub import requires standalone mode';
			return $report;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
		global $user;

		// Preload active tokens for auto-matching
		$tokenObj = new DMMToken($this->db);
		$allTokens = $tokenObj->fetchAll(1);

		// Cache: owner → matched token id (optimization)
		$ownerTokenCache = array();

		// Public entries dominate a typical hub and each one needs exactly one
		// manifest lookup, so fetch them all up front in parallel instead of
		// paying a serial round trip per module inside the loop below. Private
		// entries stay sequential: they need a token probe to know which
		// credential (if any) can even see the repo.
		$prefetch = array();
		foreach ($hub['modules'] as $idx => $entry) {
			$repoPath = $entry['repo'] ?? '';
			if (empty($repoPath) || strpos($repoPath, '/') === false || empty($entry['public'])) {
				continue;
			}
			list($pfOwner, $pfRepo) = explode('/', $repoPath, 2);
			$prefetch[$idx] = array('owner' => $pfOwner, 'repo' => $pfRepo, 'token' => null);
		}
		$this->error = '';
		$prefetchedManifests = $this->fetchManifestsBatch($prefetch);
		if (!empty($this->error)) {
			// Typically the API quota: report it, because otherwise every module
			// silently registers with a repo-name fallback id and no metadata.
			$report['errors'][] = $this->error;
		}

		foreach ($hub['modules'] as $idx => $entry) {
			$repoPath = $entry['repo'] ?? '';
			if (empty($repoPath) || strpos($repoPath, '/') === false) {
				continue;
			}

			$isPublic = !empty($entry['public']);
			if ($isPublic) {
				$report['public']++;
			} else {
				$report['private']++;
			}

			list($owner, $repoName) = explode('/', $repoPath, 2);

			// Resolve module_id from dmm.json or fallback to repo name
			$matchedTokenId = null;
			$matchedPlainToken = null;
			$manifest = null;

			if ($isPublic) {
				$manifest = $prefetchedManifests[$idx] ?? null;
			} else {
				// Try to find a token that can access this repo
				// Owner cache first
				if (isset($ownerTokenCache[$owner])) {
					$matchedTokenId = $ownerTokenCache[$owner]['id'];
					$matchedPlainToken = $ownerTokenCache[$owner]['token'];
					$manifest = $this->fetchManifest($owner, $repoName, $matchedPlainToken);
				} else {
					foreach ($allTokens as $t) {
						$plain = $t->getDecryptedToken();
						$check = $this->githubApiCall('/repos/'.$owner.'/'.$repoName, $plain);
						if ($check !== null && $check['code'] === 200) {
							$matchedTokenId = $t->id;
							$matchedPlainToken = $plain;
							$ownerTokenCache[$owner] = array('id' => $t->id, 'token' => $plain);
							$manifest = $this->fetchManifest($owner, $repoName, $plain);
							break;
						}
					}
				}
			}

			$module_id = $manifest['module_id'] ?? strtolower(preg_replace('/[^a-z0-9_]/i', '', $repoName));

			// Check if already registered (by module_id first, then by github_repo).
			// If found AND the row is a "No token" private row, re-run the token probe
			// so a newly-added GitHub PAT can heal the row in place. Otherwise skip.
			$existing = new DMMModule($this->db);
			$alreadyRegistered = ($existing->fetch(0, $module_id) > 0);
			if (!$alreadyRegistered) {
				$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($repoPath)."'";
				$resCheck = $this->db->query($sqlCheck);
				if ($resCheck && $this->db->num_rows($resCheck) > 0) {
					$obj = $this->db->fetch_object($resCheck);
					$alreadyRegistered = ($existing->fetch((int) $obj->rowid) > 0);
				}
			}

			if ($alreadyRegistered) {
				// Healing path: private row that was created without a matching token,
				// retry the probe now that the user may have added one.
				$needsHealing = (!$isPublic
					&& empty($existing->fk_dmm_token)
					&& !empty($existing->cache_last_error)
					&& strpos($existing->cache_last_error, 'No token') === 0);
				if ($needsHealing) {
					$match = $this->tryMatchTokenForRepo($owner, $repoName, $ownerTokenCache);
					if ($match !== null) {
						$existing->fk_dmm_token = $match['token_id'];
						$existing->cache_last_error = null;
						if ($existing->update($user) > 0) {
							$report['healed']++;
						} else {
							$report['errors'][] = 'Heal failed for '.$module_id.': '.$existing->error;
						}
						continue;
					}
				}
				$report['skipped']++;
				continue;
			}

			// Register
			$mod = new DMMModule($this->db);
			$mod->module_id = $module_id;
			$mod->github_repo = $repoPath;
			$mod->name = $manifest['name'] ?? ($entry['name'] ?? null);
			$mod->description = $manifest['description'] ?? ($entry['description'] ?? null);
			$mod->author = $manifest['author'] ?? null;
			$mod->license = $manifest['license'] ?? null;
			$mod->url = $manifest['url'] ?? ($entry['url'] ?? null);

			if ($isPublic) {
				$mod->fk_dmm_token = null;
			} elseif ($matchedTokenId) {
				$mod->fk_dmm_token = $matchedTokenId;
				$report['matched']++;
			} else {
				$mod->fk_dmm_token = null;
				$mod->cache_last_error = 'No token with access to this repo';
				$report['needs_token']++;
			}

			// The registry holds what is installed here, not everything a hub
			// advertises. A hub is a catalogue: it is browsed in "Add a module",
			// and a row appears only once the files are actually on disk.
			$localDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (!is_dir($localDir) || !is_dir($localDir.'/core/modules')) {
				$report['skipped']++;
				continue;
			}

			$mod->installed = 1;
			$descFiles = glob($localDir.'/core/modules/mod*.class.php');
			if (!empty($descFiles)) {
				$content = file_get_contents($descFiles[0]);
				if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $vm)) {
					$mod->installed_version = $vm[1];
				}
			}

			$createResult = $mod->create($user);
			if ($createResult > 0) {
				$report['registered']++;
			} else {
				$report['errors'][] = 'Failed to register '.$module_id;
			}
		}

		// Process referenced hubs (single pass, no recursion into already-visited URLs)
		static $visitedHubs = array();
		$visitedHubs[$url] = true;

		if (!empty($hub['hubs']) && is_array($hub['hubs'])) {
			if (function_exists('dmm_get_hubs') && function_exists('dmm_save_hubs') && function_exists('dmm_hub_identity')) {
				$existingHubs = dmm_get_hubs();
				// Index existing hubs by canonical identity so a sub-hub already known
				// under another URL form is matched (and its enabled flag honoured).
				$byId = array();
				foreach ($existingHubs as $eh) {
					$byId[dmm_hub_identity($eh['url'])] = $eh;
				}

				foreach ($hub['hubs'] as $subHubUrl) {
					if (!is_string($subHubUrl)) {
						continue;
					}
					$subId = dmm_hub_identity($subHubUrl);
					if (isset($visitedHubs[$subId])) {
						continue;
					}
					$visitedHubs[$subId] = true;

					if (!isset($byId[$subId])) {
						// Discovered sub-hub: register it disabled and DO NOT fetch it.
						// Auto-fetching a hub URL listed inside remote content lets a
						// hostile hub drive requests (and token probing) to arbitrary URLs.
						// The admin enables it explicitly to opt in.
						$newHub = array('url' => $subHubUrl, 'enabled' => 0);
						$existingHubs[] = $newHub;
						$byId[$subId] = $newHub;
						continue;
					}
					// Only import from a sub-hub the admin has already enabled.
					if (empty($byId[$subId]['enabled'])) {
						continue;
					}
					$subReport = $this->importFromHub($subHubUrl);
					$report['registered'] += $subReport['registered'];
					$report['skipped'] += $subReport['skipped'];
				}
				dmm_save_hubs($existingHubs);
			}
		}

		// Cache hub content for display
		if (function_exists('dmm_set_setting')) {
			dmm_set_setting('hub_cache_'.md5($url), json_encode(array(
				'name' => $report['hub_name'],
				'total' => $report['total'],
				'public' => $report['public'],
				'private' => $report['private'],
			)));
			dmm_set_setting('hub_last_fetch_'.md5($url), gmdate('Y-m-d H:i:s'));
		}

		return $report;
	}

	/**
	 * Parse the dmm.json manifest from a repository.
	 *
	 * @param  string      $owner Repo owner
	 * @param  string      $repo  Repo name
	 * @param  string|null $token GitHub token
	 * @return array|null         Parsed manifest or null if not found
	 */
	public function fetchManifest($owner, $repo, $token, $module_id = null)
	{
		$result = $this->githubApiCall('/repos/'.$owner.'/'.$repo.'/contents/dmm.json', $token);
		if ($result === null || $result['code'] !== 200) {
			return null;
		}

		return $this->decodeManifestBody($result['body'], $module_id);
	}

	/**
	 * Decode a raw /contents/dmm.json API body into a validated manifest.
	 *
	 * Shared by fetchManifest() and fetchManifestsBatch() so the sequential and
	 * concurrent paths can never disagree on what a valid manifest is.
	 *
	 * @param  string      $body      Raw JSON body from the contents endpoint
	 * @param  string|null $module_id Module id, to exempt DMM itself from the schema gate
	 * @return array|null             Parsed manifest, or null when absent/unsupported
	 */
	private function decodeManifestBody($body, $module_id = null)
	{
		$data = json_decode($body, true);
		if (!isset($data['content'])) {
			return null;
		}

		$manifest = json_decode(base64_decode($data['content']), true);
		if (!is_array($manifest) || !isset($manifest['schema_version'])) {
			return null;
		}

		if ($manifest['schema_version'] !== '1' && $module_id !== 'dolimodulemanager') {
			$this->error = 'Unsupported dmm.json schema_version: '.$manifest['schema_version'].'. Update DMM to the latest version.';
			return null;
		}

		return $manifest;
	}

	/**
	 * Cache key for one repository's dmm.json lookup.
	 *
	 * Keyed on owner/repo only: the same manifest is the same manifest whether it
	 * was reached through a hub, a token scan, or by hand. llx_dmm_setting.name is
	 * varchar(128), so the hash keeps the key short whatever the repo is called.
	 *
	 * @param  string $owner Repo owner
	 * @param  string $repo  Repo name
	 * @return string        Setting key
	 */
	private function manifestCacheKey($owner, $repo)
	{
		return 'manifest_cache_'.md5(strtolower($owner.'/'.$repo));
	}

	/**
	 * Read a cached manifest lookup.
	 *
	 * A cache entry records what was found *and* what was not: a repo with no
	 * dmm.json is the common case in a hub, and re-learning that on every refresh
	 * is exactly the round trip this cache exists to avoid.
	 *
	 * @param  string $owner Repo owner
	 * @param  string $repo  Repo name
	 * @return array|null    ['etag'=>?string,'manifest'=>?array,'ts'=>int] or null when absent
	 */
	private function readManifestCache($owner, $repo)
	{
		if (!function_exists('dmm_get_setting')) {
			return null;
		}
		$raw = dmm_get_setting($this->manifestCacheKey($owner, $repo), null);
		if (empty($raw)) {
			return null;
		}
		$entry = json_decode($raw, true);
		if (!is_array($entry) || !array_key_exists('manifest', $entry)) {
			return null;
		}
		return $entry;
	}

	/**
	 * Store a manifest lookup result.
	 *
	 * @param  string      $owner    Repo owner
	 * @param  string      $repo     Repo name
	 * @param  array|null  $manifest Parsed manifest, or null when the repo has none
	 * @param  string|null $etag     ETag from the response, for later revalidation
	 * @return void
	 */
	private function writeManifestCache($owner, $repo, $manifest, $etag)
	{
		if (!function_exists('dmm_set_setting')) {
			return;
		}
		dmm_set_setting($this->manifestCacheKey($owner, $repo), json_encode(array(
			'etag' => $etag,
			'manifest' => $manifest,
			'ts' => dol_now(),
		)));
	}

	/**
	 * Drop every cached repository lookup: manifests and file-existence probes.
	 *
	 * @return int Number of entries removed
	 */
	public function clearManifestCache()
	{
		$removed = 0;
		foreach (array('manifest_cache_%', 'probe_cache_%') as $pattern) {
			$sql = "DELETE FROM ".$this->db->prefix()."dmm_setting WHERE name LIKE '".$this->db->escape($pattern)."'";
			$resql = $this->db->query($sql);
			if ($resql) {
				$removed += (int) $this->db->affected_rows($resql);
			}
		}
		return $removed;
	}

	/**
	 * Fetch several dmm.json manifests concurrently, reusing cached results.
	 *
	 * A hub import needs one manifest per listed module, and doing that in a loop
	 * costs one full round trip each: 14 modules measured at ~0.40s apiece was
	 * 6.4s of the run, most of it spent learning that a repo has no dmm.json at
	 * all. Same curl_multi treatment already used for the DoliStore catalog sweep.
	 *
	 * On top of that, every lookup is revalidated with If-None-Match rather than
	 * refetched. GitHub answers an unchanged file with a 304 carrying no body —
	 * and, decisively here, a 304 does not count against the API rate limit. That
	 * matters more than the bandwidth: unauthenticated callers get 60 requests an
	 * hour, which one mid-sized hub can exhaust on its own.
	 *
	 * Requests are issued in windows so a large hub cannot open hundreds of
	 * sockets at once, and a failed handle simply yields null for that key —
	 * a missing manifest is a normal outcome here, not an error.
	 *
	 * @param  array $requests Keyed list of ['owner'=>,'repo'=>,'token'=>,'module_id'=>]
	 * @param  bool  $useCache Set false to bypass the cache and force a full refetch
	 * @return array           Same keys => manifest array or null
	 */
	public function fetchManifestsBatch(array $requests, $useCache = true)
	{
		$out = array();
		foreach ($requests as $key => $req) {
			$out[$key] = null;
		}
		if (empty($requests)) {
			return $out;
		}

		// Two tiers, because a 304 is free of rate-limit cost but still a full
		// round trip — worth ~0.1s per repo, which a wide token scan multiplies.
		//
		//  - Checked within the freshness window: trust it, no request at all.
		//  - Older: revalidate with If-None-Match, so an unchanged file answers
		//    304 (no body, not billed) and only real changes cost anything.
		$cached = array();
		$pending = $requests;
		if ($useCache) {
			$freshUntil = dol_now() - self::MANIFEST_CACHE_TTL;
			foreach ($requests as $key => $req) {
				$entry = $this->readManifestCache($req['owner'], $req['repo']);
				if ($entry === null) {
					continue;
				}
				$cached[$key] = $entry;
				if (!empty($entry['ts']) && $entry['ts'] > $freshUntil) {
					$out[$key] = $entry['manifest'];
					unset($pending[$key]);
				}
			}
		}
		if (empty($pending)) {
			return $out;
		}

		// No curl_multi (rare, but the DoliStore client guards for it too): fall
		// back to the sequential path rather than failing the whole import.
		if (!function_exists('curl_multi_init')) {
			foreach ($pending as $key => $req) {
				$out[$key] = $this->fetchManifest($req['owner'], $req['repo'], $req['token'] ?? null, $req['module_id'] ?? null);
				$this->writeManifestCache($req['owner'], $req['repo'], $out[$key], null);
			}
			return $out;
		}

		$maxConcurrent = 8;
		foreach (array_chunk($pending, $maxConcurrent, true) as $chunk) {
			$mh = curl_multi_init();
			$handles = array();

			foreach ($chunk as $key => $req) {
				$headers = array('User-Agent: DMM/1.0', 'Accept: application/vnd.github+json');
				if (!empty($req['token'])) {
					$headers[] = 'Authorization: Bearer '.$req['token'];
				}
				if (!empty($cached[$key]['etag'])) {
					$headers[] = 'If-None-Match: "'.$cached[$key]['etag'].'"';
				}
				$ch = curl_init('https://api.github.com/repos/'.$req['owner'].'/'.$req['repo'].'/contents/dmm.json');
				curl_setopt_array($ch, array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_FOLLOWLOCATION => true,
					// Needed to tell "no dmm.json" (a normal 404) apart from an
					// exhausted API quota (403 + X-RateLimit-Remaining: 0).
					CURLOPT_HEADER => true,
				));
				curl_multi_add_handle($mh, $ch);
				$handles[$key] = $ch;
			}

			$running = null;
			do {
				curl_multi_exec($mh, $running);
				curl_multi_select($mh, 1.0);
			} while ($running > 0);

			foreach ($handles as $key => $ch) {
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$raw = curl_multi_getcontent($ch);
				$rawHeaders = is_string($raw) ? substr($raw, 0, $headerSize) : '';
				$body = is_string($raw) ? substr($raw, $headerSize) : '';

				$respEtag = null;
				if (preg_match('/^ETag:\s*"?(?:W\/)?"?([^"\r\n]+)"?\s*$/mi', $rawHeaders, $em)) {
					$respEtag = $em[1];
				}

				if ($code === 304) {
					// Unchanged since we last looked: the cached value is authoritative,
					// including a cached "this repo has no dmm.json".
					$out[$key] = $cached[$key]['manifest'];
				} elseif ($code === 200 && $body !== '') {
					$out[$key] = $this->decodeManifestBody($body, $chunk[$key]['module_id'] ?? null);
					$this->writeManifestCache($chunk[$key]['owner'], $chunk[$key]['repo'], $out[$key], $respEtag);
				} elseif ($code === 404) {
					// No dmm.json here. Worth remembering: in a typical hub most repos
					// answer this way, and it is the bulk of what the refresh re-learns.
					$this->writeManifestCache($chunk[$key]['owner'], $chunk[$key]['repo'], null, $respEtag);
				} elseif ($code === 403 && preg_match('/^X-RateLimit-Remaining:\s*0/mi', $rawHeaders)) {
					// Every remaining lookup would fail the same way, and silently
					// returning null here would look like "these modules have no
					// manifest" — the one wrong conclusion to draw from a quota wall.
					$resetTxt = '';
					if (preg_match('/^X-RateLimit-Reset:\s*(\d+)/mi', $rawHeaders, $rm)) {
						$resetTxt = ' Resets at '.dol_print_date((int) $rm[1], 'dayhour', 'gmt').' UTC.';
					}
					$this->error = 'GitHub API rate limit exceeded.'.$resetTxt.' Add a GitHub token in Sources for a higher limit.';
					$rateLimited = true;
				}
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);
			}
			curl_multi_close($mh);

			// Stop early: further windows would only burn time re-hitting the wall.
			if (!empty($rateLimited)) {
				break;
			}
		}

		// Behind a quota wall (or any failed lookup), a stale cached manifest beats
		// nothing: without it the caller falls back to a repo-name id and no
		// metadata, which is worse than slightly out-of-date truth.
		if ($useCache) {
			foreach ($out as $key => $value) {
				if ($value === null && isset($cached[$key]['manifest']) && $cached[$key]['manifest'] !== null) {
					$out[$key] = $cached[$key]['manifest'];
				}
			}
		}

		return $out;
	}

	/**
	 * Parse <!-- dmm --> block from a release body.
	 *
	 * @param  string $release_body Markdown body of a GitHub release
	 * @return array|null           Parsed compatibility data or null
	 */
	public function parseReleaseBlock($release_body)
	{
		if (!preg_match('/<!--\s*dmm\s*\n([\s\S]*?)-->/', $release_body, $matches)) {
			return null;
		}

		$block = $matches[1];
		$result = array();

		$lines = explode("\n", $block);
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line) || $line[0] === '#') {
				continue;
			}
			$parts = explode(':', $line, 2);
			if (count($parts) === 2) {
				$key = trim($parts[0]);
				$value = trim($parts[1]);
				$result[$key] = $value;
			}
		}

		if (empty($result)) {
			return null;
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Check if a database table exists
	 *
	 * @param  string $tableName Table name without prefix
	 * @return bool
	 */
	private function tableExists($tableName)
	{
		$fullName = $this->db->prefix().$tableName;

		// Use Dolibarr's portable primitive (works on MySQL, PostgreSQL, SQLite).
		// Avoid "SHOW TABLES LIKE" which is MySQL-only and fails on PostgreSQL,
		// leaving DMM stuck in non-standalone mode (no hub import, empty registry).
		if (method_exists($this->db, 'DDLListTables')) {
			$database = isset($this->db->database_name) ? $this->db->database_name : '';
			$tables = $this->db->DDLListTables($database, $fullName);
			if (is_array($tables)) {
				foreach ($tables as $t) {
					if (strcasecmp((string) $t, $fullName) === 0) {
						return true;
					}
				}
				return false;
			}
		}

		// Defensive fallback: portable information_schema lookup.
		$sql = "SELECT table_name FROM information_schema.tables WHERE table_name = '".$this->db->escape($fullName)."'";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve GitHub token for a module
	 *
	 * @param  string      $module_id Module ID
	 * @param  string|null $token     Explicit token or null
	 * @return string|null
	 */
	private function resolveToken($module_id, $token)
	{
		if (!empty($token)) {
			return $token;
		}

		if ($this->standalone) {
			// Look up token from llx_dmm_module -> llx_dmm_token
			$sql = "SELECT t.token FROM ".$this->db->prefix()."dmm_token t";
			$sql .= " INNER JOIN ".$this->db->prefix()."dmm_module m ON m.fk_dmm_token = t.rowid";
			$sql .= " WHERE m.module_id = '".$this->db->escape($module_id)."'";
			$sql .= " AND t.status = 1";
			$sql .= " LIMIT 1";

			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				return dolDecrypt($obj->token);
			}
		} else {
			// Embedded mode: read from llx_const
			$key = 'DMMCLIENT_'.strtoupper($module_id).'_TOKEN';
			$sql = "SELECT value FROM ".$this->db->prefix()."const WHERE name = '".$this->db->escape($key)."' AND entity IN (0, ".((int) getEntity('')).")";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				return dolDecrypt($obj->value);
			}
		}

		// Fallback: use the default token (marked "use for public repos") for rate limit benefit
		if ($this->standalone) {
			$sql = "SELECT token FROM ".$this->db->prefix()."dmm_token";
			$sql .= " WHERE status = 1 AND use_for_public = 1";
			$sql .= " ORDER BY rowid ASC LIMIT 1";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				return dolDecrypt($obj->token);
			}
		}

		return null;
	}

	/**
	 * Resolve GitHub repo for a module
	 *
	 * @param  string      $module_id Module ID
	 * @param  string|null $repo      Explicit repo or null
	 * @return string|null            owner/repo format
	 */
	private function resolveRepo($module_id, $repo)
	{
		if (!empty($repo)) {
			$parsed = $this->parseRepoSpec($repo);
			return $parsed['repo'];
		}

		if ($this->standalone) {
			$sql = "SELECT github_repo FROM ".$this->db->prefix()."dmm_module";
			$sql .= " WHERE module_id = '".$this->db->escape($module_id)."'";
			$sql .= " LIMIT 1";

			$resql = $this->db->query($sql);
				if ($resql && $this->db->num_rows($resql) > 0) {
					$obj = $this->db->fetch_object($resql);
					$parsed = $this->parseRepoSpec($obj->github_repo);
					return $parsed['repo'];
			}
		} else {
			// Embedded mode: read from llx_const
			$key = 'DMMCLIENT_'.strtoupper($module_id).'_REPO';
			$sql = "SELECT value FROM ".$this->db->prefix()."const WHERE name = '".$this->db->escape($key)."' AND entity IN (0, ".((int) getEntity('')).")";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				return $obj->value;
			}
		}

		// Try dmm.json in the module directory
		$dmmJsonPath = DOL_DOCUMENT_ROOT.'/custom/'.$module_id.'/dmm.json';
		if (file_exists($dmmJsonPath)) {
			$data = json_decode(file_get_contents($dmmJsonPath), true);
			if (isset($data['repository'])) {
				$parsed = $this->parseRepoSpec($data['repository']);
				return $parsed['repo'];
			}
		}

		return null;
	}

	/**
	 * Call the GitHub API
	 *
	 * @param  string      $endpoint API endpoint (e.g., /repos/owner/repo/releases)
	 * @param  string|null $token    Bearer token
	 * @param  string|null $etag     ETag for conditional requests
	 * @return array|null            ['code' => int, 'body' => string, 'etag' => string|null]
	 */
	private function githubApiCall($endpoint, $token = null, $etag = null)
	{
		$url = 'https://api.github.com'.$endpoint;

		$headers = array(
			'User-Agent: DMM/1.0',
			'Accept: application/vnd.github+json',
		);
		if (!empty($token)) {
			$headers[] = 'Authorization: Bearer '.$token;
		}
		if (!empty($etag)) {
			$headers[] = 'If-None-Match: '.$etag;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HEADER => true,
		));

		$response = curl_exec($ch);
		if ($response === false) {
			$this->error = 'cURL error: '.curl_error($ch);
			curl_close($ch);
			return null;
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);

		$responseHeaders = substr($response, 0, $headerSize);
		$body = substr($response, $headerSize);

		// Extract ETag from response headers
		$responseEtag = null;
		if (preg_match('/^ETag:\s*"?([^"\r\n]+)"?\s*$/mi', $responseHeaders, $m)) {
			$responseEtag = $m[1];
		}

		// Extract rate limit headers
		$rateLimit = null;
		$rateLimitRemaining = null;
		$rateLimitReset = null;
		if (preg_match('/^X-RateLimit-Limit:\s*(\d+)/mi', $responseHeaders, $m)) {
			$rateLimit = (int) $m[1];
		}
		if (preg_match('/^X-RateLimit-Remaining:\s*(\d+)/mi', $responseHeaders, $m)) {
			$rateLimitRemaining = (int) $m[1];
		}
		if (preg_match('/^X-RateLimit-Reset:\s*(\d+)/mi', $responseHeaders, $m)) {
			$rateLimitReset = (int) $m[1];
		}

		// Surface user-friendly rate limit error
		if ($httpCode === 403 && $rateLimitRemaining === 0 && $rateLimitReset !== null) {
			$resetTime = dol_print_date($rateLimitReset, 'dayhour', 'gmt');
			$body = json_encode(array(
				'message' => 'GitHub API rate limit exceeded. Resets at '.$resetTime.' UTC. '
					.'Limit: '.$rateLimit.'/hour. Use a GitHub token for higher limits.',
				'rate_limit_reset' => $rateLimitReset,
			));
		}

		return array(
			'code' => $httpCode,
			'body' => $body,
			'etag' => $responseEtag,
			'rate_limit_remaining' => $rateLimitRemaining,
			'rate_limit_reset' => $rateLimitReset,
		);
	}

	/**
	 * Resolve compatibility constraints for a version
	 *
	 * @param  string     $version      Module version (e.g., "1.3.0")
	 * @param  array|null $manifest     Parsed dmm.json manifest
	 * @param  string     $release_body Release body text
	 * @return array|null               Compatibility data or null
	 */
	private function resolveCompatibility($version, $manifest, $release_body)
	{
		// Priority 1: release block overrides manifest
		$releaseBlock = $this->parseReleaseBlock($release_body);
		if ($releaseBlock !== null && isset($releaseBlock['dolibarr_min'])) {
			return $releaseBlock;
		}

		// Priority 2: manifest compatibility matrix
		if (!empty($manifest['compatibility']) && is_array($manifest['compatibility'])) {
			$compat = $manifest['compatibility'];

			// Exact match
			if (isset($compat[$version])) {
				return $compat[$version];
			}

			// Minor wildcard: e.g., "1.3.x"
			$parts = explode('.', $version);
			if (count($parts) >= 2) {
				$minorWild = $parts[0].'.'.$parts[1].'.x';
				if (isset($compat[$minorWild])) {
					return $compat[$minorWild];
				}
			}

			// Major wildcard: e.g., "1.x"
			if (count($parts) >= 1) {
				$majorWild = $parts[0].'.x';
				if (isset($compat[$majorWild])) {
					return $compat[$majorWild];
				}
			}
		}

		return null;
	}

	/**
	 * Check if environment meets compatibility constraints
	 *
	 * @param  array  $constraints    Compatibility data (dolibarr_min, dolibarr_max, php_min, php_max)
	 * @param  string $dolibarrVersion Current Dolibarr version
	 * @param  string $phpVersion      Current PHP version
	 * @return bool
	 */
	private function isCompatible($constraints, $dolibarrVersion, $phpVersion)
	{
		// Dolibarr min
		if (!empty($constraints['dolibarr_min'])) {
			if (version_compare($dolibarrVersion, $constraints['dolibarr_min'], '<')) {
				return false;
			}
		}

		// Dolibarr max (supports wildcards like "20.*")
		if (!empty($constraints['dolibarr_max'])) {
			$maxBound = $this->expandWildcardMax($constraints['dolibarr_max']);
			if ($maxBound !== null && version_compare($dolibarrVersion, $maxBound, '>=')) {
				return false;
			}
		}

		// PHP min
		if (!empty($constraints['php_min'])) {
			if (version_compare($phpVersion, $constraints['php_min'], '<')) {
				return false;
			}
		}

		// PHP max
		if (!empty($constraints['php_max']) && $constraints['php_max'] !== '*') {
			$maxBound = $this->expandWildcardMax($constraints['php_max']);
			if ($maxBound !== null && version_compare($phpVersion, $maxBound, '>=')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Expand a wildcard version max to the upper bound.
	 * e.g., "20.*" -> "21.0.0", "20.1.*" -> "20.2.0"
	 *
	 * @param  string      $version Version with wildcard
	 * @return string|null          Upper bound version or null if no wildcard
	 */
	private function expandWildcardMax($version)
	{
		if (strpos($version, '*') === false) {
			// No wildcard — treat as inclusive upper bound
			// Add .999 to make it inclusive for version_compare
			return $version.'.999';
		}

		$parts = explode('.', str_replace('*', '', rtrim($version, '.')));
		$parts = array_filter($parts, function ($p) {
			return $p !== '';
		});
		$parts = array_values($parts);

		if (empty($parts)) {
			return null; // "*" alone means no limit
		}

		// Increment last non-wildcard segment
		$last = count($parts) - 1;
		$parts[$last] = ((int) $parts[$last]) + 1;

		return implode('.', $parts).'.0.0';
	}

	/**
	 * Get the installed version of a module from its descriptor
	 *
	 * @param  string      $module_id Module ID
	 * @return string|null            Version string or null
	 */
	public function getInstalledVersion($module_id)
	{
		$customDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
		if (!is_dir($customDir)) {
			return null;
		}

		$descriptorFile = $this->findDescriptor($customDir);
		if (!$descriptorFile) {
			return null;
		}

		// Parse version from descriptor without including the file. Common
		// patterns in the wild:
		//   1. literal:  $this->version = '1.2.3';
		//   2. file:     $this->version = file_get_contents(__DIR__.'/../../VERSION');
		//      …possibly wrapped: $this->version = trim(file_get_contents(__DIR__.'/../../VERSION'));
		//   3. constant: $this->version = self::VERSION; (with `const VERSION = '1.2.3';`)
		$content = file_get_contents($descriptorFile);
		if ($content === false) {
			return null;
		}
		if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
			return $m[1];
		}
		// Tolerate an optional wrapper call (trim/rtrim/…) around file_get_contents,
		// e.g. open-dsi modules use `= trim(file_get_contents(__DIR__.'/../../VERSION'))`.
		if (preg_match('/\$this->version\s*=\s*(?:[a-zA-Z_]\w*\s*\(\s*)?file_get_contents\s*\(\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
			$versionFile = realpath(dirname($descriptorFile).$m[1]);
			if ($versionFile && is_file($versionFile)) {
				$v = trim((string) @file_get_contents($versionFile));
				if ($v !== '') {
					return $v;
				}
			}
		}
		if (preg_match('/\$this->version\s*=\s*self::([A-Z_]+)\s*;/', $content, $m)) {
			$constName = $m[1];
			if (preg_match('/const\s+'.preg_quote($constName, '/').'\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $cm)) {
				return $cm[1];
			}
		}

		return null;
	}

	/**
	 * Find the module descriptor file in a directory
	 *
	 * @param  string       $dir Directory to search
	 * @return string|false      Path to descriptor or false
	 */
	public function findDescriptor($dir)
	{
		$coreModulesDir = $dir.'/core/modules/';
		if (!is_dir($coreModulesDir)) {
			return false;
		}

		$files = glob($coreModulesDir.'mod*.class.php');
		if (!empty($files)) {
			return $files[0];
		}

		return false;
	}

	/**
	 * Download a tarball from GitHub
	 *
	 * @param  string      $owner  Repo owner
	 * @param  string      $repo   Repo name
	 * @param  string      $tag    Git tag
	 * @param  string|null $token  GitHub token
	 * @param  string      $dest   Destination file path
	 * @return array               ['success' => bool, 'message' => string]
	 */
	private function downloadTarball($owner, $repo, $tag, $token, $dest)
	{
		$url = 'https://api.github.com/repos/'.$owner.'/'.$repo.'/tarball/'.$tag;

		$dir = dirname($dest);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		$fp = fopen($dest, 'wb');
		if (!$fp) {
			return array('success' => false, 'message' => 'Cannot create temp file: '.$dest);
		}

		$headers = array(
			'User-Agent: DMM/1.0',
			'Accept: application/vnd.github+json',
		);
		if (!empty($token)) {
			$headers[] = 'Authorization: Bearer '.$token;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 120,
		));

		$success = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if (!$success || $httpCode !== 200) {
			@unlink($dest);
			return array('success' => false, 'message' => 'Download failed with HTTP '.$httpCode);
		}

		return array('success' => true, 'message' => 'Downloaded to '.$dest);
	}

	/**
	 * Extract a .tar.gz archive using PharData
	 *
	 * @param  string $tarGzPath Path to .tar.gz file
	 * @param  string $extractTo Destination directory
	 * @return array             ['success' => bool, 'message' => string]
	 */
	private function extractTarball($tarGzPath, $extractTo)
	{
		if (!is_dir($extractTo)) {
			@mkdir($extractTo, 0755, true);
		}

		try {
			$phar = new PharData($tarGzPath);
			$phar->decompress();
			unset($phar); // Release PharData handle to avoid "already exists" on .tar open

			$tarPath = preg_replace('/\.gz$/', '', $tarGzPath);
			$tar = new PharData($tarPath);
			$tar->extractTo($extractTo);
			unset($tar);

			return array('success' => true, 'message' => 'Extracted to '.$extractTo);
		} catch (Exception $e) {
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * Find the module root directory inside an extracted git archive.
	 * Git hosting services (GitHub + GitLab) wrap content in one top-level directory.
	 *
	 * Supports three repo layouts:
	 * 1. Module at root:          wrapper/core/modules/modXxx.class.php
	 * 2. Module in subfolder:     wrapper/mymodule/core/modules/modXxx.class.php
	 * 3. Monorepo subdirectory:   wrapper/{subdir}/core/modules/modXxx.class.php
	 *    (when $subdir is set from the YAML /tree/{branch}/{path} parsing)
	 *
	 * @param  string       $extractDir Base extraction directory
	 * @param  string       $module_id  Expected module ID
	 * @param  string|null  $subdir     Monorepo subdirectory within the wrapper
	 * @return string|false             Path to module root or false
	 */
	private function findModuleRoot($extractDir, $module_id, $subdir = null)
	{
		$entries = scandir($extractDir);
		$dirs = array();
		foreach ($entries as $e) {
			if ($e === '.' || $e === '..') {
				continue;
			}
			if (is_dir($extractDir.'/'.$e)) {
				$dirs[] = $e;
			}
		}

		if (empty($dirs)) {
			return false;
		}

		// GitHub/GitLab tarballs always have exactly one top-level wrapper dir:
		//   github:  owner-repo-hash/
		//   gitlab:  project-branch-sha/
		$wrapperDir = $extractDir.'/'.$dirs[0];

		// Case 0: Explicit monorepo subdir declared in the module row. Takes priority
		// over the fallback scan because a monorepo typically contains many modules
		// and we must not accidentally pick a sibling.
		if (!empty($subdir)) {
			// Sanitize: strip slashes to prevent path traversal, keep only simple segments.
			$cleanSubdir = ltrim(trim((string) $subdir), '/');
			$cleanSubdir = preg_replace('#\.\./#', '', $cleanSubdir);
			if ($cleanSubdir !== '') {
				$candidate = $wrapperDir.'/'.$cleanSubdir;
				if (is_dir($candidate) && $this->findDescriptor($candidate)) {
					return $candidate;
				}
				// Subdir was declared but descriptor missing — surface clearly instead
				// of silently falling through to sibling modules.
				$this->error = 'Monorepo subdir "'.$cleanSubdir.'" has no valid Dolibarr module descriptor';
				return false;
			}
		}

		// Case 1: Module descriptor directly in wrapper (module files at repo root)
		// e.g., wrapper/core/modules/modXxx.class.php
		if ($this->findDescriptor($wrapperDir)) {
			return $wrapperDir;
		}

		// Case 2: Module in a subdirectory matching module_id
		// e.g., wrapper/dolimodulemanager/core/modules/modXxx.class.php
		$subDir = $wrapperDir.'/'.$module_id;
		if (is_dir($subDir) && $this->findDescriptor($subDir)) {
			return $subDir;
		}

		// Case 3: Scan all immediate subdirectories for a descriptor
		$subEntries = scandir($wrapperDir);
		foreach ($subEntries as $se) {
			if ($se === '.' || $se === '..') {
				continue;
			}
			$candidate = $wrapperDir.'/'.$se;
			if (is_dir($candidate) && $this->findDescriptor($candidate)) {
				return $candidate;
			}
		}

		// Fallback: return wrapper dir (let the caller's descriptor check catch issues)
		return $wrapperDir;
	}

	/**
	 * Create a backup of a module before update
	 *
	 * @param  string $module_id Module ID
	 * @param  string $newTag    New version tag being installed
	 * @return array             ['success' => bool, 'message' => string, 'backup_path' => string|null]
	 */
	private function createBackup($module_id, $newTag)
	{
		$sourceDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
		if (!is_dir($sourceDir)) {
			return array('success' => true, 'message' => 'Nothing to backup', 'backup_path' => null);
		}

		$currentVersion = $this->getInstalledVersion($module_id) ?: 'unknown';
		$timestamp = date('YmdHis');
		$backupDir = DOL_DATA_ROOT.'/dolimodulemanager/backups/'.$module_id.'_'.$currentVersion.'_'.$timestamp;

		if (!is_dir(dirname($backupDir))) {
			@mkdir(dirname($backupDir), 0755, true);
		}

		$result = dolCopyDir($sourceDir, $backupDir, '0', 1);
		if ($result < 0) {
			return array('success' => false, 'message' => 'Failed to copy module to backup directory', 'backup_path' => null);
		}

		// Calculate backup size
		$size = $this->dirSize($backupDir);

		// Record in database if standalone
		if ($this->standalone) {
			dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
			dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

			$mod = new DMMModule($this->db);
			$modResult = $mod->fetch(0, $module_id);

			$backup = new DMMBackup($this->db);
			// fk_dmm_module is nullable (FK ON DELETE SET NULL) since 1.7.0; a
			// missing module row is a valid case (e.g. install-from-marketplace
			// before the registry row was canonicalized).
			$backup->fk_dmm_module = ($modResult > 0) ? $mod->id : null;
			$backup->module_id = $module_id;
			$backup->version_from = $currentVersion;
			$backup->version_to = ltrim($newTag, 'vV');
			$backup->backup_path = $backupDir;
			$backup->backup_size = $size;

			global $user;
			$backup->create($user);
		}

		return array('success' => true, 'message' => 'Backup created', 'backup_path' => $backupDir);
	}

	/**
	 * Restore a module from a backup directory
	 *
	 * @param  string $module_id   Module ID
	 * @param  string $backup_path Backup directory path
	 * @return array               ['success' => bool, 'message' => string]
	 */
	private function restoreFromBackup($module_id, $backup_path)
	{
		if (empty($backup_path) || !is_dir($backup_path)) {
			return array('success' => false, 'message' => 'Backup directory not found: '.$backup_path);
		}

		$targetDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;

		if (is_dir($targetDir)) {
			dol_delete_dir_recursive($targetDir);
			// Verify deletion succeeded (prevents merged/corrupted state from locked files)
			if (is_dir($targetDir)) {
				return array('success' => false, 'message' => 'Failed to remove current module directory: '.$targetDir.'. Files may be locked.');
			}
		}

		$result = dolCopyDir($backup_path, $targetDir, '0', 1);
		if ($result < 0) {
			return array('success' => false, 'message' => 'Failed to restore from backup');
		}

		return array('success' => true, 'message' => 'Module '.$module_id.' restored from backup');
	}

	/**
	 * Update module cache in llx_dmm_module
	 *
	 * @param  string $module_id Module ID
	 * @param  array  $data      Cache data
	 * @return void
	 */
	private function updateModuleCache($module_id, $data)
	{
		if (!$this->standalone) {
			return true;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		$mod = new DMMModule($this->db);
		if ($mod->fetch(0, $module_id) > 0) {
			$mod->updateCache($data);
		}
	}

	/**
	 * Sync installed status from filesystem to database.
	 * If module exists in /custom/ but DB says installed=0, fix it.
	 *
	 * @param  string $module_id        Module ID
	 * @param  string $installedVersion Version found on disk
	 * @return void
	 */
	private function syncInstalledStatus($module_id, $installedVersion)
	{
		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		$mod = new DMMModule($this->db);
		if ($mod->fetch(0, $module_id) > 0) {
			if (!$mod->installed || $mod->installed_version !== $installedVersion) {
				$mod->installed = 1;
				$mod->installed_version = $installedVersion;
				global $user;
				$mod->update($user);
			}
		}
	}

	/**
	 * Update module registry after install/update
	 *
	 * @param  string $module_id Module ID
	 * @param  string $version   New version
	 * @return void
	 */
	private function updateModuleRegistry($module_id, $version)
	{
		if (!$this->standalone) {
			return;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		$mod = new DMMModule($this->db);
		if ($mod->fetch(0, $module_id) <= 0) {
			return false;
		}
		$mod->installed_version = $version;
		$mod->installed = 1;
		$mod->invalidateCache();
		global $user;
		return $mod->update($user) > 0;
	}

	/** Stage and atomically promote extracted module content. */
	private function deployModuleDirectory($sourceDir, $targetDir, $isUpdate)
	{
		$stagingDir = $targetDir.'.dmmnew';
		$oldDir = $targetDir.'.dmmold';
		$this->cleanupDir($stagingDir);
		$this->cleanupDir($oldDir);

		if (!@rename($sourceDir, $stagingDir) && !$this->recursiveCopy($sourceDir, $stagingDir)) {
			$this->cleanupDir($stagingDir);
			return array('success' => false, 'message' => 'Failed to stage module files in '.$stagingDir.($this->error ? ' ('.$this->error.')' : ''));
		}
		if (!$this->findDescriptor($stagingDir)) {
			$this->cleanupDir($stagingDir);
			return array('success' => false, 'message' => 'Module descriptor not found after staging');
		}
		if ($isUpdate && !@rename($targetDir, $oldDir)) {
			$this->cleanupDir($stagingDir);
			return array('success' => false, 'message' => 'Failed to move current module aside: '.$targetDir);
		}
		if (!@rename($stagingDir, $targetDir)) {
			if ($isUpdate) {
				@rename($oldDir, $targetDir);
			}
			$this->cleanupDir($stagingDir);
			return array('success' => false, 'message' => 'Failed to promote module into '.$targetDir);
		}
		$this->cleanupDir($oldDir);
		return array('success' => true, 'message' => '');
	}

	/**
	 * Sanitize module ID
	 *
	 * @param  string       $id Module ID
	 * @return string|false
	 */
	public function sanitizeModuleId($id)
	{
		if (function_exists('dmm_sanitize_module_id')) {
			return dmm_sanitize_module_id($id);
		}
		$id = trim(strtolower($id));
		if (!preg_match('/^[a-z0-9_]+$/', $id)) {
			return false;
		}
		return $id;
	}

	/**
	 * Check if module ID is a core Dolibarr module
	 *
	 * @param  string $id Module ID
	 * @return bool
	 */
	private function isCoreModule($id)
	{
		if (function_exists('dmm_is_core_module')) {
			return dmm_is_core_module($id);
		}
		// Basic check: see if module exists in core/modules
		$file = DOL_DOCUMENT_ROOT.'/core/modules/mod'.ucfirst($id).'.class.php';
		return file_exists($file);
	}

	/**
	 * Whether a URL targets GitHub (host exactly github.com / *.github.com or the
	 * raw/api subdomains). Used to gate sending stored PATs — a token must never be
	 * attached to a request for an arbitrary hub host.
	 *
	 * @param  string $url URL to inspect
	 * @return bool
	 */
	private function isGithubHost($url)
	{
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));
		return $host === 'github.com' || $host === 'api.github.com' || $host === 'raw.githubusercontent.com'
			|| (substr($host, -11) === '.github.com');
	}

	/**
	 * Check write permissions on a directory and its contents.
	 * Samples a few files/dirs to detect permission issues early.
	 *
	 * @param  string      $dir Directory to check
	 * @return string|null      Error message or null if OK
	 */
	private function checkWritePermissions($dir)
	{
		if (!is_writable($dir)) {
			$mode = substr(sprintf('%o', @fileperms($dir)), -4);
			$owner = function_exists('dmm_get_file_owner') ? dmm_get_file_owner($dir) : '?';
			return $dir.' is not writable (mode:'.$mode.' owner:'.$owner.')';
		}

		// Check a sample of subdirectories and files
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$checked = 0;
		foreach ($iterator as $item) {
			if (!is_writable($item->getPathname())) {
				$mode = substr(sprintf('%o', @fileperms($item->getPathname())), -4);
				$owner = function_exists('dmm_get_file_owner') ? dmm_get_file_owner($item->getPathname()) : '?';
				return $item->getPathname().' is not writable (mode:'.$mode.' owner:'.$owner.')';
			}
			$checked++;
			if ($checked >= 20) {
				break;
			}
		}

		return null;
	}

	/**
	 * Recursively copy a directory, overwriting existing files.
	 * Uses native PHP functions only — no dependency on Dolibarr file helpers.
	 *
	 * @param  string $src  Source directory
	 * @param  string $dest Destination directory
	 * @return bool         True on success
	 */
	private function recursiveCopy($src, $dest)
	{
		if (!is_dir($src)) {
			return false;
		}
		if (!is_dir($dest)) {
			@mkdir($dest, 0755, true);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$subPath = $iterator->getSubPathname();
			$target = $dest.'/'.$subPath;

			if ($item->isDir()) {
				if (!is_dir($target)) {
					@mkdir($target, 0755, true);
				}
			} else {
				$targetDir = dirname($target);
				if (!is_dir($targetDir)) {
					@mkdir($targetDir, 0755, true);
				}
				if (!@copy($item->getPathname(), $target)) {
					$this->error = 'Failed to copy: '.$subPath;
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get temp directory for downloads
	 *
	 * @return string
	 */
	private function getTempDir()
	{
		global $conf;

		if (function_exists('dmm_get_setting')) {
			$custom = dmm_get_setting('temp_dir');
			if (!empty($custom) && is_dir($custom)) {
				return $custom;
			}
		}

		if (!empty($conf->dolimodulemanager->dir_temp)) {
			return $conf->dolimodulemanager->dir_temp;
		}

		$dir = DOL_DATA_ROOT.'/dolimodulemanager/temp';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		return $dir;
	}

	/**
	 * Calculate directory size recursively
	 *
	 * @param  string $dir Directory path
	 * @return int         Size in bytes
	 */
	private function dirSize($dir)
	{
		$size = 0;
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$size += $file->getSize();
		}
		return $size;
	}

	/**
	 * Recursively delete a directory
	 *
	 * @param  string $dir Directory path
	 * @return void
	 */
	private function cleanupDir($dir)
	{
		if (is_dir($dir)) {
			dol_delete_dir_recursive($dir);
		}
	}

	// -------------------------------------------------------------------------
	// Uninstall (1.9.0, developer mode)
	// -------------------------------------------------------------------------

	/**
	 * Fully uninstall a module from custom/: backup the directory, call the
	 * descriptor's remove() when the module is still enabled (menus, rights,
	 * constants, boxes, crons, MAIN_MODULE_xxx), then delete the directory and
	 * mark the registry row as not installed.
	 *
	 * Never touches the module's own tables, extrafields or DOL_DATA_ROOT
	 * documents — same convention as Dolibarr itself.
	 *
	 * @param  string $module_id   Directory name under custom/
	 * @param  bool   $deleteFiles Delete the directory (default). False = metadata-only
	 *                             uninstall: disable + registry, files left in place —
	 *                             for installs where the PHP user does not own the
	 *                             module files (FTP/SSH deployments) and deletion must
	 *                             be done by hand.
	 * @return array               ['success' => bool, 'message' => string, 'backup_path' => string|null, 'disabled' => bool, 'files_deleted' => bool, 'code' => string|null]
	 */
	public function uninstallModule($module_id, $deleteFiles = true)
	{
		global $conf, $user;

		$module_id = function_exists('dmm_sanitize_module_id') ? dmm_sanitize_module_id($module_id) : basename($module_id);
		$out = array('success' => false, 'message' => '', 'backup_path' => null, 'disabled' => false, 'files_deleted' => false, 'code' => null);

		if (empty($module_id)) {
			$out['message'] = 'Invalid module id';
			return $out;
		}
		if ($module_id === 'dolimodulemanager') {
			$out['message'] = 'DMM cannot uninstall itself';
			return $out;
		}
		if (function_exists('dmm_is_core_module') && dmm_is_core_module($module_id)) {
			$out['message'] = 'Core modules cannot be uninstalled';
			return $out;
		}

		$targetDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
		if (!is_dir($targetDir)) {
			$out['message'] = 'Directory not found: '.$targetDir;
			return $out;
		}
		if ($deleteFiles) {
			$permError = $this->checkWritePermissions($targetDir);
			if ($permError) {
				$phpUser = function_exists('dmm_get_php_user') ? dmm_get_php_user() : 'www-data';
				$out['code'] = 'not_writable';
				$out['message'] = $permError.' — fix ownership (e.g. chown -R '.$phpUser.' '.$targetDir.') or uninstall keeping the files and delete the directory manually.';
				return $out;
			}
		}

		// 1. Backup (recorded in llx_dmm_backup when standalone)
		$backup = $this->createBackup($module_id, 'uninstall');
		if (empty($backup['success'])) {
			$out['message'] = $backup['message'];
			return $out;
		}
		$out['backup_path'] = $backup['backup_path'];

		// 2. Disable through the descriptor so Dolibarr metadata is cleaned
		$className = $this->getDescriptorClass($module_id);
		if ($className) {
			$descriptorFile = $targetDir.'/core/modules/'.$className.'.class.php';
			try {
				require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
				include_once $descriptorFile;
				if (class_exists($className)) {
					$modInstance = new $className($this->db);
					$constName = !empty($modInstance->const_name) ? $modInstance->const_name : 'MAIN_MODULE_'.strtoupper($module_id);
					if (getDolGlobalInt($constName) || !empty($conf->global->$constName)) {
						$res = $modInstance->remove('');
						if ($res < 0) {
							$out['message'] = 'remove() failed: '.($modInstance->error ?: implode(', ', (array) $modInstance->errors));
							return $out;
						}
						$out['disabled'] = true;
					}
				}
			} catch (Throwable $e) {
				$out['message'] = 'Descriptor remove() threw: '.$e->getMessage();
				return $out;
			}
		}

		// 3. Delete files
		if ($deleteFiles) {
			dol_delete_dir_recursive($targetDir);
			if (is_dir($targetDir)) {
				$out['message'] = 'Failed to delete '.$targetDir;
				return $out;
			}
			$out['files_deleted'] = true;
		}

		// 4. Registry
		if ($this->standalone) {
			dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
			$mod = new DMMModule($this->db);
			if ($mod->fetch(0, $module_id) > 0) {
				$mod->installed = 0;
				$mod->installed_version = null;
				if (method_exists($mod, 'invalidateCache')) {
					$mod->invalidateCache();
				}
				$mod->update($user);
			}
		}

		$out['success'] = true;
		$out['message'] = 'Module '.$module_id.' uninstalled';
		return $out;
	}

	// -------------------------------------------------------------------------
	// Dev channel + community YAML (1.6.0)
	// -------------------------------------------------------------------------

	/**
	 * Load a fully-hydrated DMMModule row by module_id, or null if not found.
	 *
	 * @param  string         $module_id Module ID
	 * @return DMMModule|null
	 */
	private function loadModuleRow($module_id)
	{
		if (!$this->standalone) {
			return null;
		}
		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		$mod = new DMMModule($this->db);
		if ($mod->fetch(0, $module_id) > 0) {
			return $mod;
		}
		return null;
	}

	/**
	 * Probe every active GitHub token in llx_dmm_token to find one that can read the
	 * given owner/repo. First match wins.
	 *
	 * Uses GET /repos/{owner}/{repo} — that's the cheapest "can I see this?" call
	 * (no release listing, no tarball fetch, single API hit per token in the worst case).
	 *
	 * @param  string     $owner      GitHub owner/org
	 * @param  string     $repo       GitHub repo name
	 * @param  array|null $ownerCache Optional by-reference cache keyed by owner so a batch
	 *                                caller (like importFromHub) doesn't re-probe the same
	 *                                owner for every module. Format:
	 *                                ['owner' => ['id' => int, 'token' => string]].
	 * @return array|null             ['token_id' => int, 'plain_token' => string] or null
	 *                                if no active token can read the repo.
	 */
	private function tryMatchTokenForRepo($owner, $repo, &$ownerCache = null)
	{
		if (!$this->standalone) {
			return null;
		}
		// Owner cache short-circuit — only used when the caller passes a shared cache.
		if ($ownerCache !== null && isset($ownerCache[$owner])) {
			return array(
				'token_id' => $ownerCache[$owner]['id'],
				'plain_token' => $ownerCache[$owner]['token'],
			);
		}

		dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
		$tokenObj = new DMMToken($this->db);
		$allTokens = $tokenObj->fetchAll(1);
		foreach ($allTokens as $t) {
			$plain = $t->getDecryptedToken();
			if (empty($plain)) {
				continue;
			}
			$check = $this->githubApiCall('/repos/'.$owner.'/'.$repo, $plain);
			if ($check !== null && $check['code'] === 200) {
				if ($ownerCache !== null) {
					$ownerCache[$owner] = array('id' => $t->id, 'token' => $plain);
				}
				return array('token_id' => $t->id, 'plain_token' => $plain);
			}
		}
		return null;
	}

	/**
	 * Resolve the HEAD commit SHA of a branch. Host-aware dispatch.
	 *
	 * @param  string      $owner     Repo owner
	 * @param  string      $repo      Repo name
	 * @param  string      $branch    Branch name
	 * @param  string|null $token     Optional token
	 * @param  string      $gitHost   'github' (default) or 'gitlab'
	 * @param  string|null $baseUrl   Base URL for the GitLab instance (e.g. https://inligit.fr)
	 * @return string|null            Full SHA or null on error
	 */
	private function fetchBranchSha($owner, $repo, $branch, $token = null, $gitHost = 'github', $baseUrl = null)
	{
		if ($gitHost === 'gitlab') {
			// For GitLab, $owner may contain slashes (group namespaces); combine with $repo
			// into the full project path and URL-encode it as a single path segment.
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			$res = $this->gitlabApiCall($baseUrl, '/projects/'.rawurlencode($project).'/repository/branches/'.rawurlencode($branch), $token);
			if ($res === null || $res['code'] !== 200) {
				return null;
			}
			$data = json_decode($res['body'], true);
			if (!is_array($data) || empty($data['commit']['id'])) {
				return null;
			}
			return (string) $data['commit']['id'];
		}
		// GitHub
		$res = $this->githubApiCall('/repos/'.$owner.'/'.$repo.'/branches/'.rawurlencode($branch), $token);
		if ($res === null || $res['code'] !== 200) {
			return null;
		}
		$data = json_decode($res['body'], true);
		if (!is_array($data) || empty($data['commit']['sha'])) {
			return null;
		}
		return (string) $data['commit']['sha'];
	}

	/**
	 * List the branches available on a repository. Host-aware (github/gitlab).
	 *
	 * Used by the per-module branch picker (admin/module.php) so a user can follow
	 * any branch — not just the single branch_dev declared in the manifest. The
	 * caller decides which branch to persist into the module row's branch_dev column.
	 *
	 * @param  string      $owner   Repo owner (or GitLab namespace)
	 * @param  string      $repo    Repo name
	 * @param  string|null $token   Optional token (GitHub only)
	 * @param  string      $gitHost 'github' (default) or 'gitlab'
	 * @param  string|null $baseUrl       GitLab base URL (ignored for github)
	 * @param  bool        $withFreshness Also resolve the tip date and default branch for the developer picker
	 * @return array<int,array{name:string,sha:string,committed_at:?string,default:bool}>|null Branch list or null on API error
	 */
	public function listBranches($owner, $repo, $token = null, $gitHost = 'github', $baseUrl = null, $withFreshness = false)
	{
		// Both hosts cap a page at 100. A repo with more branches than that would
		// silently lose everything past the first page — the branch simply would
		// not appear in the picker, with nothing to say why. Walk the pages, with
		// a ceiling so a pathological repo cannot spin here forever.
		$maxPages = 10;
		$branches = array();

		if ($gitHost === 'gitlab') {
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			for ($page = 1; $page <= $maxPages; $page++) {
				$res = $this->gitlabApiCall($baseUrl, '/projects/'.rawurlencode($project).'/repository/branches?per_page=100&page='.$page, $token);
				if ($res === null || $res['code'] !== 200) {
					// A later page failing after some succeeded still leaves a usable
					// list; only a failure on the first page means we know nothing.
					if ($page === 1) {
						$this->error = ucfirst($gitHost).' branch list failed: HTTP '.($res['code'] ?? '0');
						return null;
					}
					break;
				}
				$data = json_decode($res['body'], true);
				if (!is_array($data) || empty($data)) {
					break;
				}
				foreach ($data as $b) {
					if (!empty($b['name'])) {
						$branches[] = array(
							'name' => (string) $b['name'],
							'sha' => (string) ($b['commit']['id'] ?? ''),
							'committed_at' => $withFreshness ? (string) ($b['commit']['committed_date'] ?? '') : null,
							'default' => $withFreshness && !empty($b['default']),
						);
					}
				}
				if (count($data) < 100) {
					break;
				}
			}
			return $branches;
		}

		// GitHub. Its branch-list response deliberately contains only the tip SHA,
		// not the commit date. Enrichment therefore costs one request per branch;
		// keep it exclusive to the explicit developer-mode AJAX action and cap it
		// so large repositories cannot exhaust the API quota.
		$defaultBranch = $withFreshness ? $this->gitDefaultBranch($owner, $repo, $token, $gitHost, $baseUrl) : null;
		// Anonymous GitHub access is limited to a small hourly quota. A configured
		// token allows a richer comparison; without one, sample only a few branches
		// (plus the default branch) rather than consuming most of that quota at once.
		$freshnessBudget = empty($token) ? 8 : 30;
		for ($page = 1; $page <= $maxPages; $page++) {
			$res = $this->githubApiCall('/repos/'.$owner.'/'.$repo.'/branches?per_page=100&page='.$page, $token);
			if ($res === null || $res['code'] !== 200) {
				if ($page === 1) {
					$this->error = 'GitHub branch list failed: HTTP '.($res['code'] ?? '0');
					return null;
				}
				break;
			}
			$data = json_decode($res['body'], true);
			if (!is_array($data) || empty($data)) {
				break;
			}
			foreach ($data as $b) {
				if (!empty($b['name'])) {
					$sha = (string) ($b['commit']['sha'] ?? '');
					$committedAt = null;
					$isDefault = ($defaultBranch !== null && $b['name'] === $defaultBranch);
					if ($withFreshness && $sha !== '' && ($freshnessBudget > 0 || $isDefault)) {
						$commitRes = $this->githubApiCall('/repos/'.$owner.'/'.$repo.'/commits/'.rawurlencode($sha), $token);
						if ($commitRes !== null && $commitRes['code'] === 200) {
							$commit = json_decode($commitRes['body'], true);
							$committedAt = (string) ($commit['commit']['committer']['date'] ?? $commit['commit']['author']['date'] ?? '');
						}
						if (!$isDefault) {
							$freshnessBudget--;
						}
					}
					$branches[] = array(
						'name' => (string) $b['name'],
						'sha' => $sha,
						'committed_at' => $committedAt,
						'default' => $isDefault,
					);
				}
			}
			if (count($data) < 100) {
				break;
			}
		}
		return $branches;
	}

	/**
	 * Resolve a repository's real default branch. Host-aware (github/gitlab).
	 *
	 * Avoids assuming "main"/"master": self-hosted GitLab instances often use a
	 * different default (e.g. open-dsi uses a year-named branch like "2026"). Used
	 * as the branch-HEAD fallback when a repo exposes no release.
	 *
	 * @param  string      $owner   Repo owner (or GitLab namespace)
	 * @param  string      $repo    Repo name
	 * @param  string|null $token   Optional token
	 * @param  string      $gitHost 'github' (default) or 'gitlab'
	 * @param  string|null $baseUrl GitLab base URL (ignored for github)
	 * @return string|null          Default branch name, or null if it can't be read.
	 */
	private function gitDefaultBranch($owner, $repo, $token = null, $gitHost = 'github', $baseUrl = null)
	{
		if ($gitHost === 'gitlab') {
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			$res = $this->gitlabApiCall($baseUrl, '/projects/'.rawurlencode($project), $token);
		} else {
			$res = $this->githubApiCall('/repos/'.$owner.'/'.$repo, $token);
		}
		if ($res === null || $res['code'] !== 200) {
			return null;
		}
		$data = json_decode($res['body'], true);
		if (!is_array($data) || empty($data['default_branch'])) {
			return null;
		}
		return (string) $data['default_branch'];
	}

	/**
	 * The version a branch-tracking source publishes for itself, if any.
	 *
	 * The community index carries a current_version per entry, which the import
	 * stores on the row. That number is the only version statement the source
	 * makes: the repository holds many modules and cuts no tags, so there is no
	 * ref to read a version from. Where it exists it beats a branch SHA, which
	 * names a commit rather than a release and can never equal the semver the
	 * installed descriptor declares.
	 *
	 * Restricted to sources that are branch-backed by distribution rather than by
	 * preference. A module on the dev channel because the user asked for dev
	 * builds is asking to track commits, and must keep comparing SHAs.
	 *
	 * @param  DMMModule|null $row Registry row, or null when not standalone
	 * @return string|null         Published version, or null when there is none
	 */
	private function publishedBranchVersion($row)
	{
		if (empty($row) || ($row->source ?? '') !== 'dolibarr-community') {
			return null;
		}

		$moduleId = (string) ($row->module_id ?? '');
		if ($moduleId === '') {
			return null;
		}

		// Deliberately not read from cache_latest_version, even though the import
		// writes it there: invalidateCache() clears that column on every install, so
		// a check running straight after one would find nothing, fall back to the
		// SHA, and report an update against the version it had just installed.
		// The index is the thing that actually states the version, so ask it.
		$index = $this->communityVersionIndex();

		return $index[$moduleId] ?? null;
	}

	/**
	 * module_id => published version, from the community index.
	 *
	 * Memoised per request and backed by the settings cache, because this is
	 * consulted by every update check and every branch install: without it a
	 * dashboard-wide check would refetch the same YAML once per module.
	 *
	 * A failure returns an empty map rather than propagating: callers treat "no
	 * published version" as "compare SHAs", which is the pre-existing behaviour
	 * and safe. It costs a needless update prompt at worst, never a bad install.
	 *
	 * @return array<string,string>
	 */
	private function communityVersionIndex()
	{
		if ($this->communityVersions !== null) {
			return $this->communityVersions;
		}

		$cacheKey = 'community_versions_cache';
		$cached = json_decode((string) dmm_get_setting($cacheKey, ''), true);
		if (is_array($cached) && isset($cached['at'], $cached['map'])
			&& (dol_now('gmt') - (int) $cached['at']) < self::MANIFEST_CACHE_TTL) {
			$this->communityVersions = (array) $cached['map'];
			return $this->communityVersions;
		}

		$this->communityVersions = array();

		$url = dmm_get_setting('community_yaml_url', '');
		if ($url !== '') {
			$entries = $this->fetchCommunityYaml($url);
			if (is_array($entries)) {
				foreach ($entries as $entry) {
					$name = $entry['modulename'] ?? '';
					$version = trim((string) ($entry['current_version'] ?? ''));
					if ($name === '' || $version === '') {
						continue;
					}
					$id = $this->sanitizeModuleId($name);
					if ($id !== false) {
						$this->communityVersions[$id] = $version;
					}
				}
			}
		}

		dmm_set_setting($cacheKey, json_encode(array(
			'at' => dol_now('gmt'),
			'map' => $this->communityVersions,
		)));

		return $this->communityVersions;
	}

	/**
	 * Check whether the dev branch has moved since the locally installed SHA.
	 * Returns the same shape as checkUpdate() so callers don't need to special-case.
	 *
	 * @param  string      $module_id Module ID
	 * @param  string      $owner     Repo owner
	 * @param  string      $repo      Repo name
	 * @param  string      $branch    Dev branch name
	 * @param  string|null $token     Optional token
	 * @param  string      $gitHost   'github' (default) or 'gitlab'
	 * @param  string|null $baseUrl   GitLab base URL
	 * @return array|null
	 */
	private function checkDevBranchUpdate($module_id, $owner, $repo, $branch, $token, $gitHost = 'github', $baseUrl = null)
	{
		$sha = $this->fetchBranchSha($owner, $repo, $branch, $token, $gitHost, $baseUrl);
		if ($sha === null) {
			$this->error = 'Failed to read dev branch HEAD: '.($this->error ?: 'unknown error');
			if ($this->standalone) {
				$this->updateModuleCache($module_id, array('error' => $this->error));
			}
			return null;
		}

		$shortSha = substr($sha, 0, 12);
		$latestVersion = 'dev:'.$shortSha;
		$installedVersion = $this->getInstalledVersion($module_id);
		// On dev channel, the installed_version is stored as 'dev:{sha}' in the registry.
		// Fall back to the registry row when the on-disk descriptor reports a stable semver.
		$registryInstalled = null;
		$row = null;
		if ($this->standalone) {
			$row = $this->loadModuleRow($module_id);
			if ($row && !empty($row->installed_version) && strpos($row->installed_version, 'dev:') === 0) {
				$registryInstalled = $row->installed_version;
			}
		}
		$compareInstalled = $registryInstalled ?: $installedVersion;
		$updateAvailable = ($compareInstalled !== $latestVersion);

		// A source that tracks a branch but still publishes a version number is the
		// normal case for the community index: the repository cuts no tags (so the
		// branch is the only ref to install from), yet every entry carries a
		// current_version, and the module on disk declares the matching semver.
		// Reporting the branch SHA there loses real information twice over — the
		// card shows "dev:b720d7e3ce8d" instead of 1.0.3, and every check calls an
		// update available, because a SHA never equals a semver.
		//
		// So compare what the two sides actually state. The download still uses the
		// branch: only the versions reported and compared change.
		$publishedVersion = $this->publishedBranchVersion($row);
		if ($publishedVersion !== null) {
			$diskVersion = $installedVersion;
			$latestVersion = $publishedVersion;
			$compareInstalled = $diskVersion ?: $compareInstalled;
			$updateAvailable = ($diskVersion === null)
				|| version_compare($publishedVersion, $diskVersion, '>');
		}

		$result = array(
			'update_available'         => $updateAvailable,
			'installed_version'        => $compareInstalled,
			'latest_version'           => $latestVersion,
			'latest_compatible_version' => $latestVersion,
			'changelog'                => '',
			// Unchanged by the published-version path above: the branch is still the
			// only ref this repository offers, so it stays what gets downloaded.
			'download_tag'             => $branch,
			'verified'                 => false,
			'channel'                  => 'dev',
			'dev_branch'               => $branch,
			'dev_sha'                  => $sha,
			'checked_at'               => gmdate('c'),
		);

		if ($this->standalone) {
			$this->updateModuleCache($module_id, array(
				'latest_version'    => $latestVersion,
				'latest_compatible' => $latestVersion,
				'changelog'         => '',
			));
		}

		return $result;
	}

	/**
	 * Fetch and parse the Dolibarr community modules index.yaml.
	 *
	 * Uses ext-yaml when available, otherwise a narrow regex-based parser scoped to
	 * the flat top-level structure of the official index.yaml. This is intentionally
	 * not a general YAML parser — only the fields documented in section 17 of the
	 * DMM specification are extracted.
	 *
	 * @param  string $url URL to index.yaml
	 * @return array|null  List of normalized entries, or null on fetch error
	 */
	public function fetchCommunityYaml($url)
	{
		if (!preg_match('#^https?://#i', $url)) {
			$this->error = 'Invalid community YAML URL';
			return null;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array('User-Agent: DMM/1.0', 'Accept: text/yaml, text/plain, */*'),
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
		));
		$body = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErrNo = curl_errno($ch);
		$curlErr = curl_error($ch);
		curl_close($ch);

		if ($body === false || $httpCode !== 200) {
			// HTTP 0 means no response was ever received (DNS, TLS, timeout, or PHP
			// pulling the rug out mid-request). Reporting the curl error instead is
			// the difference between "HTTP 0" and something the user can act on.
			$this->error = $httpCode > 0
				? 'Failed to fetch community YAML: HTTP '.$httpCode
				: 'Failed to fetch community YAML: '.($curlErr !== '' ? $curlErr : 'no response from server').' (curl '.$curlErrNo.')';
			return null;
		}

		return $this->parseCommunityYaml($body);
	}

	/**
	 * Parse the Dolibarr community index.yaml into a normalized array of entries.
	 *
	 * The real file has the shape:
	 *   packages:
	 *       - modulename: 'Xxx'
	 *         label:
	 *             en: '...'
	 *             fr: '...'
	 *         description:
	 *             en: '...'
	 *         git: 'https://...'
	 *         ...
	 *
	 * So nested mappings (label, description) need to stay nested. We prefer ext-yaml
	 * and fall back to an indent-tracking mini-parser scoped to this specific file
	 * shape (flat string scalars + one level of nested language maps).
	 *
	 * @param  string $yaml Raw YAML
	 * @return array        List of entries (may be empty)
	 */
	public function parseCommunityYaml($yaml)
	{
		// Prefer ext-yaml when present (much more robust)
		if (function_exists('yaml_parse')) {
			$parsed = @yaml_parse($yaml);
			if (is_array($parsed)) {
				return $this->extractCommunityEntries($parsed);
			}
		}

		// Indent-tracking fallback parser scoped to the community index.yaml shape.
		// The real file has:
		//   packages:
		//       - modulename: 'Foo'                <- entry indent = 4 (content at col 6)
		//         label:                           <- "field" indent = 8
		//             en: '...'                    <- nested value indent = 12
		//             fr: '...'
		//         description:
		//             en: '...'
		//         git: '...'
		//         status: 'enabled'
		//
		// We track three indent levels: entryIndent (col of "-"), fieldIndent (col of
		// sibling keys under the entry), and stay inside a nested mapping only while
		// the current line's indent is STRICTLY greater than fieldIndent.
		$entries = array();
		$current = null;
		$entryIndent = -1;
		$fieldIndent = -1;
		$nestedKey = null;

		$lines = preg_split('/\r\n|\r|\n/', $yaml);
		foreach ($lines as $line) {
			$rawLine = rtrim($line);
			if ($rawLine === '' || preg_match('/^\s*#/', $rawLine)) {
				continue;
			}
			// Indent = leading whitespace (tabs = 4 spaces, defensive)
			$expanded = str_replace("\t", '    ', $rawLine);
			$indent = strlen($expanded) - strlen(ltrim($expanded, ' '));
			$content = ltrim($expanded, ' ');

			// Top-level wrapper key like "packages:" — ignore, we'll catch the list items
			if ($indent === 0 && preg_match('/^([a-zA-Z0-9_-]+):\s*$/', $content)) {
				continue;
			}

			// List item: "- key: value" starts a new entry
			if (substr($content, 0, 2) === '- ') {
				if ($current !== null) {
					$entries[] = $current;
				}
				$current = array();
				$nestedKey = null;
				$entryIndent = $indent;
				// The fields of this entry begin 2 columns to the right of the dash.
				$fieldIndent = $indent + 2;
				$rest = substr($content, 2);
				if ($rest !== '' && preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $rest, $m)) {
					$k = $m[1];
					$v = $this->unquoteScalar($m[2]);
					if ($v === '' && !$this->isQuotedEmpty($m[2])) {
						$nestedKey = $k;
						$current[$k] = array();
					} else {
						$current[$k] = $v;
					}
				}
				continue;
			}

			if ($current === null) {
				continue;
			}
			if (!preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $content, $m)) {
				continue;
			}
			$key = $m[1];
			$value = $this->unquoteScalar($m[2]);

			// If the indent is deeper than a sibling field of the current entry, we're
			// still inside a nested mapping opened by the most recent field-level key.
			if ($indent > $fieldIndent && $nestedKey !== null) {
				if (!is_array($current[$nestedKey] ?? null)) {
					$current[$nestedKey] = array();
				}
				if ($value !== '') {
					$current[$nestedKey][$key] = $value;
				}
				continue;
			}

			// Sibling field at entry level (indent == fieldIndent, or indent < previous nested).
			if ($value === '' && !$this->isQuotedEmpty($m[2])) {
				// Open a new nested mapping block (e.g. "label:", "description:")
				$nestedKey = $key;
				$current[$key] = array();
			} else {
				$current[$key] = $value;
				$nestedKey = null;
			}
		}
		if ($current !== null) {
			$entries[] = $current;
		}
		return $entries;
	}

	/**
	 * Extract community entries from a parsed YAML structure. Accepts either a flat
	 * list or a map with a "packages" (or similar) wrapper key.
	 *
	 * @param  array $parsed Parsed YAML
	 * @return array         List of normalized entries
	 */
	private function extractCommunityEntries($parsed)
	{
		// Unwrap a top-level wrapper key if present.
		if (is_array($parsed) && !isset($parsed[0])) {
			// It's a map. Prefer a known wrapper key, otherwise use the first array value.
			foreach (array('packages', 'modules', 'entries') as $wrapperKey) {
				if (isset($parsed[$wrapperKey]) && is_array($parsed[$wrapperKey])) {
					$parsed = $parsed[$wrapperKey];
					break;
				}
			}
		}
		if (!is_array($parsed)) {
			return array();
		}
		$entries = array();
		foreach ($parsed as $key => $value) {
			if (is_array($value)) {
				if (!isset($value['modulename']) && is_string($key)) {
					$value['modulename'] = $key;
				}
				$entries[] = $value;
			}
		}
		return $entries;
	}

	/**
	 * Strip YAML scalar wrapping: surrounding quotes and trailing inline comments.
	 *
	 * @param  string $value Raw scalar from the YAML source
	 * @return string
	 */
	/**
	 * Is this raw YAML value an explicitly quoted empty string?
	 *
	 * `key:` (nothing after the colon) opens a nested mapping, while `key: ""`
	 * assigns an empty string. Both unquote to '', so without this test the
	 * parser turns `phpmax: ""` — which the real community index contains — into
	 * an array, and an array can then be written into a varchar column.
	 *
	 * @param  string $raw Raw text after the colon
	 * @return bool        True for '' or "" (optionally followed by a comment)
	 */
	private function isQuotedEmpty($raw)
	{
		return (bool) preg_match('/^\s*(["\'])\1\s*(#.*)?$/', (string) $raw);
	}

	private function unquoteScalar($value)
	{
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		// Quoted scalar: strip the surrounding quotes then ignore any trailing comment.
		if (preg_match('/^(["\'])(.*?)\1(.*)$/', $value, $m)) {
			return $m[2];
		}
		// Unquoted: strip a trailing "# comment" only if it's preceded by whitespace,
		// then trim any leftover quotes or whitespace.
		$hash = strpos($value, ' #');
		if ($hash !== false) {
			$value = rtrim(substr($value, 0, $hash));
		}
		return trim($value, " \"'");
	}

	/**
	 * Import community modules into llx_dmm_module.
	 *
	 * Filters per DMM spec section 17:
	 *   - modulename + git URL present
	 *   - status == 'enabled' — OR any status when developer mode is on (those
	 *     rows are tagged with cache_last_error='upstream_status:{status}' so the
	 *     UI can render a badge and gate the install button)
	 *   - git URL parses to a supported host (github.com or known GitLab host)
	 * Monorepo entries (git URL contains '/tree/{branch}/{subdir}') are registered
	 * with a `subdir` populated so install extracts the subdirectory from the wrapper.
	 *
	 * Stale v1.6.0 rows are healed: when dedupe matches an existing row whose source
	 * is already `dolibarr-community`, the row is UPDATED from the current YAML entry
	 * (in-place heal) instead of skipped.
	 *
	 * When developer mode is toggled OFF, any previously-imported non-enabled rows
	 * are auto-deleted at the start of the import so the toggle is truly reversible.
	 *
	 * @param  array $entries Parsed entries from fetchCommunityYaml()
	 * @return array          ['total','registered','updated','skipped','monorepo','filtered','errors']
	 */
	public function importFromCommunityYaml($entries)
	{
		$report = array('total' => 0, 'registered' => 0, 'updated' => 0, 'skipped' => 0, 'monorepo' => 0, 'filtered' => 0, 'errors' => array());

		if (!$this->standalone) {
			$report['errors'][] = 'Community YAML import requires standalone mode';
			return $report;
		}

		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		global $user, $langs;

		$devMode = function_exists('dmm_is_dev_mode') && dmm_is_dev_mode();

		// When dev mode is OFF, drop any previously-imported non-enabled community
		// rows from earlier dev-mode sessions so toggling off is reversible.
		if (!$devMode) {
			$sql = "DELETE FROM ".$this->db->prefix()."dmm_module";
			$sql .= " WHERE source = 'dolibarr-community'";
			$sql .= " AND cache_last_error LIKE 'upstream_status:%'";
			$sql .= " AND installed = 0";
			$this->db->query($sql);
		}

		$lang = 'en';
		if (isset($langs) && method_exists($langs, 'getDefaultLang')) {
			$shortLang = substr($langs->getDefaultLang(), 0, 2);
			if (!empty($shortLang)) {
				$lang = $shortLang;
			}
		}

		$report['total'] = count($entries);
		foreach ($entries as $entry) {
			$moduleName = $entry['modulename'] ?? '';
			$gitUrl = $entry['git'] ?? '';
			if ($moduleName === '' || $gitUrl === '') {
				$report['filtered']++;
				continue;
			}

			// Dev-mode-aware status filter. In normal mode, only "enabled" modules
			// are imported. When developer mode is on, non-enabled entries (status:
			// soon, beta, deprecated, etc.) are ALSO imported but tagged so the UI
			// can render a badge and gate the install button.
			$rawStatus = isset($entry['status']) ? strtolower(trim((string) $entry['status'])) : 'enabled';
			$upstreamStatusMarker = null;
			if ($rawStatus !== 'enabled') {
				if (!$devMode) {
					$report['filtered']++;
					continue;
				}
				$upstreamStatusMarker = 'upstream_status:'.$rawStatus;
			}

			// Parse the git URL into host + owner/repo + subdir. Any URL whose host we
			// don't recognize (neither github.com nor a known GitLab host) is filtered.
			$parsed = $this->parseGitUrl($gitUrl);
			if ($parsed === null) {
				$report['filtered']++;
				continue;
			}
			$gitHost = $parsed['host'];
			$gitBaseUrl = $parsed['base_url'];
			// Use the full project path — critical for GitLab group namespaces
			// (e.g. cap-rel/dolibarr/plugin-facturx). GitHub paths are flat so this
			// reduces to "owner/repo" unchanged.
			$repoPath = $parsed['project'];
			$subdir = $parsed['subdir'];

			$module_id = $this->sanitizeModuleId($moduleName);
			if ($module_id === false) {
				$report['errors'][] = $moduleName.': invalid module id';
				continue;
			}

			// Pick a display name/description in the user's language (en fallback).
			$label = $this->pickLocalizedString($entry['label'] ?? null, $lang, $moduleName);
			$description = $this->pickLocalizedString($entry['description'] ?? null, $lang, null);

			// Dedupe by module_id first, then by (github_repo, subdir). The
			// subdir MUST participate in the uniqueness key for monorepo entries:
			// e.g. both HelloAsso and PDPConnectFR live at
			// Dolibarr/dolibarr-community-modules, differing only in subdir. A
			// github_repo-only check would incorrectly heal one row with the other
			// entry's data (regression fixed in 1.6.6).
			$existing = new DMMModule($this->db);
			$found = ($existing->fetch(0, $module_id) > 0);
			if (!$found) {
				$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module";
				$sqlCheck .= " WHERE github_repo = '".$this->db->escape($repoPath)."'";
				if ($subdir !== null && $subdir !== '') {
					$sqlCheck .= " AND subdir = '".$this->db->escape($subdir)."'";
				} else {
					$sqlCheck .= " AND (subdir IS NULL OR subdir = '')";
				}
				$resCheck = $this->db->query($sqlCheck);
				if ($resCheck && $this->db->num_rows($resCheck) > 0) {
					$obj = $this->db->fetch_object($resCheck);
					$found = ($existing->fetch((int) $obj->rowid) > 0);
				}
			}

			if ($found) {
				$isCommunityRow = (($existing->source ?? '') === 'dolibarr-community');
				if (!$isCommunityRow) {
					// Row came from a different source (token, hub, manual). Don't touch it.
					$report['skipped']++;
					if (!empty($subdir)) {
						$report['monorepo']++;
					}
					continue;
				}
				// Heal in place: refresh every field we own from the current YAML.
				$existing->github_repo = $repoPath;
				$existing->name = $label;
				$existing->description = $description;
				$existing->author = $entry['author'] ?? null;
				$existing->license = $entry['license'] ?? null;
				$existing->url = $entry['dolistore-download'] ?? $gitUrl;
				$existing->source = 'dolibarr-community';
				$existing->branch = $entry['git-branch'] ?? 'main';
				// The shared community repo publishes no releases, so these modules
				// only exist at branch HEAD. Say so on the row, or checkUpdate() goes
				// looking for a tag that was never cut.
				$existing->branch_dev = $existing->branch;
				$existing->channel = 'dev';
				$existing->git_host = $gitHost;
				$existing->git_base_url = $gitBaseUrl;
				$existing->subdir = $subdir;
				if ($existing->update($user) > 0) {
					// The cache_* columns are not part of update()'s SET list — they are
					// owned by updateCache(), so assigning them on the object above would
					// have looked like a heal and written nothing. Both of these are
					// statements the index makes about the module, so they belong here:
					//
					//   latest_version    the version the index publishes. Without it the
					//                     card falls back to the branch SHA a check wrote,
					//                     showing "dev:b720d7e3ce8d" in place of 1.0.3.
					//   error             writes a fresh upstream_status marker for the
					//                     badge; omitting the key clears a stale one,
					//                     which is what updateCache() does with no 'error'.
					$cacheHeal = array();
					if ($upstreamStatusMarker !== null) {
						$cacheHeal['error'] = $upstreamStatusMarker;
					}
					if (!empty($entry['current_version'])) {
						$cacheHeal['latest_version'] = (string) $entry['current_version'];
						$cacheHeal['latest_compatible'] = (string) $entry['current_version'];
					}
					$existing->updateCache($cacheHeal);

					$report['updated']++;
					if (!empty($subdir)) {
						$report['monorepo']++;
					}
				} else {
					$report['errors'][] = $moduleName.': heal failed — '.$existing->error;
				}
				continue;
			}

			// Fresh row — but only for something actually installed here. The
			// community index is a catalogue of ~everything the ecosystem
			// publishes; copying it into the registry filled a table meant for
			// installed modules with hundreds of rows describing absent ones.
			$communityDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (!is_dir($communityDir) || !is_dir($communityDir.'/core/modules')) {
				$report['skipped']++;
				continue;
			}

			$mod = new DMMModule($this->db);
			$mod->module_id = $module_id;
			$mod->github_repo = $repoPath;
			$mod->fk_dmm_token = null;
			$mod->name = $label;
			$mod->description = $description;
			$mod->author = $entry['author'] ?? null;
			$mod->license = $entry['license'] ?? null;
			$mod->url = $entry['dolistore-download'] ?? $gitUrl;
			$mod->source = 'dolibarr-community';
			$mod->branch = $entry['git-branch'] ?? 'main';
			$mod->git_host = $gitHost;
			$mod->git_base_url = $gitBaseUrl;
			$mod->subdir = $subdir;
			// Branch-backed, same as the update path above.
			$mod->branch_dev = $mod->branch;
			$mod->channel = 'dev';
			$mod->installed = 1;
			$mod->installed_version = $this->getInstalledVersion($module_id);
			if (!empty($entry['current_version'])) {
				$mod->cache_latest_version = (string) $entry['current_version'];
				$mod->cache_latest_compatible = (string) $entry['current_version'];
			}
			if (!empty($subdir)) {
				$report['monorepo']++;
			}
			if ($upstreamStatusMarker !== null) {
				$mod->cache_last_error = $upstreamStatusMarker;
			}

			$createResult = $mod->create($user);
			if ($createResult > 0) {
				$report['registered']++;
			} else {
				$report['errors'][] = $moduleName.': '.$mod->error;
			}
		}

		return $report;
	}

	/**
	 * Pick a localized string from a YAML field that may be either a scalar or a
	 * language → string map. Falls back to English, then the first value, then default.
	 *
	 * @param  mixed       $field   String, array, or null
	 * @param  string      $lang    Preferred language code (e.g. 'fr')
	 * @param  string|null $default Fallback if no value can be picked
	 * @return string|null
	 */
	private function pickLocalizedString($field, $lang, $default)
	{
		if (is_string($field)) {
			return $field;
		}
		if (is_array($field)) {
			if (isset($field[$lang])) {
				return (string) $field[$lang];
			}
			if (isset($field['en'])) {
				return (string) $field['en'];
			}
			foreach ($field as $v) {
				if (is_string($v) && $v !== '') {
					return $v;
				}
			}
		}
		return $default;
	}

	/**
	 * Parse a git URL into host + full project path + optional subdir.
	 *
	 * GitHub is simple: owner/repo. GitLab projects can live under arbitrarily deep
	 * group namespaces (e.g. cap-rel/dolibarr/plugin-facturx). Rather than forcing a
	 * two-level owner/repo structure, we store the entire project path as one string
	 * that the caller can either split on the last slash (for display) or URL-encode
	 * as a single segment (for GitLab API calls).
	 *
	 * @param  string     $gitUrl Git URL
	 * @return array|null         ['host'=>'github'|'gitlab', 'base_url'=>string|null,
	 *                             'project'=>string, 'owner'=>string, 'repo'=>string,
	 *                             'subdir'=>string|null, 'branch'=>string|null]
	 *                             or null if the host is unsupported.
	 *                             'branch' comes from a /tree/{branch}/... URL and is
	 *                             null otherwise; a subdir is only meaningful on the
	 *                             branch it was read from, so the two travel together.
	 */
	private function parseGitUrl($gitUrl)
	{
		$url = trim((string) $gitUrl);
		if ($url === '') {
			return null;
		}
		// Strip a trailing .git if present (outside of /tree/ paths).
		// First, separate any /tree/{branch}/{subdir} suffix.
		$subdir = null;
		$branch = null;
		$mainPart = $url;
		if (preg_match('#^(.*?)/tree/([^/]+)(?:/(.*))?/?$#i', $url, $tm)) {
			$mainPart = $tm[1];
			$branch = $tm[2];
			$subdir = isset($tm[3]) && $tm[3] !== '' ? rtrim($tm[3], '/') : null;
		}
		// Strip trailing .git on the main part
		$mainPart = preg_replace('#\.git/?$#', '', $mainPart);
		$mainPart = rtrim($mainPart, '/');

		// Extract scheme://host and everything after it
		if (!preg_match('#^(https?://([^/]+))/(.+)$#i', $mainPart, $m)) {
			return null;
		}
		$baseUrl = $m[1];
		$host = strtolower($m[2]);
		$projectPath = $m[3];
		if ($projectPath === '') {
			return null;
		}

		// Split into "owner" (everything before last slash) and "repo" (last segment).
		// Works for github.com/owner/repo AND for gitlab group/sub/project.
		$lastSlash = strrpos($projectPath, '/');
		if ($lastSlash === false) {
			return null;
		}
		$owner = substr($projectPath, 0, $lastSlash);
		$repo = substr($projectPath, $lastSlash + 1);
		if ($owner === '' || $repo === '') {
			return null;
		}

		if ($host === 'github.com') {
			// GitHub's API uses {owner}/{repo} — namespaces are flat (one level).
			return array(
				'host' => 'github',
				'base_url' => null,
				'project' => $projectPath,
				'owner' => $owner,
				'repo' => $repo,
				'subdir' => $subdir,
				'branch' => $branch,
			);
		}

		// Any other http(s) host with a real FQDN is treated as a self-hosted
		// GitLab instance (git.open-dsi.fr, framagit.org, inligit.fr, …). Framagit
		// and friends all expose the same /api/v4 REST API, so one generic path
		// covers every instance without a per-host allowlist. The "owner" may
		// contain slashes (GitLab group/sub-group namespaces) — $projectPath keeps
		// the full path, which gitlabApiCall() rawurlencode()s as a single id.
		// A valid host has at least one dot (rejects a bare word mistaken for a host).
		if (strpos($host, '.') === false) {
			return null;
		}
		return array(
			'host' => 'gitlab',
			'base_url' => $baseUrl,
			'project' => $projectPath,
			'owner' => $owner,
			'repo' => $repo,
			'subdir' => $subdir,
			'branch' => $branch,
		);
	}

	/**
	 * Normalise a "public repo" user input into a host-aware descriptor.
	 *
	 * Accepts either:
	 *  - a flat GitHub shortcut "owner/repo" (legacy behaviour), or
	 *  - a full git URL (github.com OR a self-hosted GitLab instance, with
	 *    nested group namespaces and an optional /tree/{branch}/{subdir} suffix).
	 *
	 * Public wrapper around parseGitUrl() so the admin "Add public repository"
	 * form does not duplicate URL-parsing rules.
	 *
	 * @param  string      $input Raw user input
	 * @return array|null         ['host','base_url','project','owner','repo','subdir']
	 *                            ('project' is the repo identifier to store in
	 *                            github_repo) — or null if unparseable.
	 */
	public function parsePublicRepoInput($input)
	{
		$input = trim((string) $input);
		if ($input === '') {
			return null;
		}

		// Full URL → delegate to the host-aware parser.
		if (preg_match('#^https?://#i', $input)) {
			return $this->parseGitUrl($input);
		}

		// Flat "owner/repo" GitHub shortcut.
		if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $input)) {
			$slash = strpos($input, '/');
			return array(
				'host' => 'github',
				'base_url' => null,
				'project' => $input,
				'owner' => substr($input, 0, $slash),
				'repo' => substr($input, $slash + 1),
				'subdir' => null,
			);
		}

		return null;
	}

	/**
	 * Extract "owner/repo" from a git URL — kept for backwards compatibility.
	 * New code should call parseGitUrl() which returns the full host context.
	 *
	 * @param  string      $gitUrl Git URL
	 * @return string|null         "owner/repo" or null if unsupported
	 */
	private function extractRepoFromGitUrl($gitUrl)
	{
		$parsed = $this->parseGitUrl($gitUrl);
		if ($parsed === null) {
			return null;
		}
		return $parsed['owner'].'/'.$parsed['repo'];
	}

	// -------------------------------------------------------------------------
	// Git host abstraction (1.6.2) — GitHub + GitLab
	// -------------------------------------------------------------------------

	/**
	 * Call a GitLab REST API endpoint. Similar to githubApiCall() but speaks
	 * GitLab's /api/v4 shape and unauthenticated public-repo access.
	 *
	 * @param  string      $baseUrl  Instance base URL (e.g. https://inligit.fr)
	 * @param  string      $path     API path starting with '/' (e.g. /projects/...)
	 * @param  string|null $token    Optional GitLab token (unused in v1.6.2 — public only)
	 * @return array|null            ['code'=>int, 'body'=>string] or null on curl error
	 */
	private function gitlabApiCall($baseUrl, $path, $token = null)
	{
		if (empty($baseUrl)) {
			$this->error = 'GitLab base URL is missing';
			return null;
		}
		$url = rtrim($baseUrl, '/').'/api/v4'.$path;

		$headers = array('User-Agent: DMM/1.0', 'Accept: application/json');
		if (!empty($token)) {
			// GitLab accepts either "PRIVATE-TOKEN: xxx" or "Authorization: Bearer xxx"
			$headers[] = 'PRIVATE-TOKEN: '.$token;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
		));
		$body = curl_exec($ch);
		if ($body === false) {
			$this->error = 'cURL error: '.curl_error($ch);
			curl_close($ch);
			return null;
		}
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return array('code' => $httpCode, 'body' => (string) $body, 'etag' => null);
	}

	/**
	 * List releases for a repo on the given git host.
	 *
	 * @param  string      $gitHost  'github' or 'gitlab'
	 * @param  string|null $baseUrl  GitLab base URL (ignored for github)
	 * @param  string      $owner    Repo owner
	 * @param  string      $repo     Repo name
	 * @param  string|null $token    Optional token
	 * @return array|null            Same shape as githubApiCall(): ['code','body','etag']
	 */
	private function gitListReleases($gitHost, $baseUrl, $owner, $repo, $token = null)
	{
		if ($gitHost === 'gitlab') {
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			return $this->gitlabApiCall($baseUrl, '/projects/'.rawurlencode($project).'/releases', $token);
		}
		return $this->githubApiCall('/repos/'.$owner.'/'.$repo.'/releases', $token);
	}

	/**
	 * Fetch dmm.json from a repo on the given git host. Returns the parsed manifest
	 * or null if not found / invalid. Host-aware replacement for fetchManifest() which
	 * stays github-only for backwards compatibility with the discovery paths.
	 *
	 * @param  string      $gitHost   'github' or 'gitlab'
	 * @param  string|null $baseUrl   GitLab base URL (ignored for github)
	 * @param  string      $owner     Repo owner
	 * @param  string      $repo      Repo name
	 * @param  string|null $branch    Branch name (needed by GitLab raw-file endpoint; defaults to "main")
	 * @param  string|null $token     Optional token
	 * @param  string|null $module_id Module ID (for schema_version bypass on self-update)
	 * @return array|null             Parsed manifest or null
	 */
	private function gitFetchManifest($gitHost, $baseUrl, $owner, $repo, $branch, $token = null, $module_id = null)
	{
		if ($gitHost === 'gitlab') {
			// Read dmm.json from the declared branch, else the repo's real default
			// branch (open-dsi defaults to a year-named branch like "2026", not main),
			// only guessing "main" if even that lookup fails.
			if (!empty($branch)) {
				$ref = $branch;
			} else {
				$ref = $this->gitDefaultBranch($owner, $repo, $token, 'gitlab', $baseUrl);
				if (empty($ref)) {
					$ref = 'main';
				}
			}
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			$res = $this->gitlabApiCall($baseUrl, '/projects/'.rawurlencode($project).'/repository/files/'.rawurlencode('dmm.json').'/raw?ref='.rawurlencode($ref), $token);
			if ($res === null || $res['code'] !== 200) {
				return null;
			}
			$manifest = json_decode($res['body'], true);
			if (!is_array($manifest) || !isset($manifest['schema_version'])) {
				return null;
			}
			if ($manifest['schema_version'] !== '1' && $module_id !== 'dolimodulemanager') {
				$this->error = 'Unsupported dmm.json schema_version: '.$manifest['schema_version'];
				return null;
			}
			return $manifest;
		}
		// GitHub — read from the declared branch, and from the module's own
		// subdirectory when the row points into a monorepo. fetchManifest() reads
		// dmm.json at the default branch's root, which is the wrong place on both
		// counts for a community entry like Dolibarr/dolibarr-community-modules.
		$path = 'dmm.json';
		if ($module_id !== null && $this->standalone) {
			$row = $this->loadModuleRow($module_id);
			if ($row && !empty($row->subdir)) {
				$path = trim($row->subdir, '/').'/dmm.json';
			}
		}

		$endpoint = '/repos/'.$owner.'/'.$repo.'/contents/'.$path;
		if (!empty($branch)) {
			$endpoint .= '?ref='.rawurlencode($branch);
		}
		$res = $this->githubApiCall($endpoint, $token);
		if ($res === null || $res['code'] !== 200) {
			return null;
		}
		return $this->decodeManifestBody($res['body'], $module_id);
	}

	/**
	 * Download a repository archive (.tar.gz) from the given git host, streaming to disk.
	 *
	 * @param  string      $gitHost 'github' or 'gitlab'
	 * @param  string|null $baseUrl GitLab base URL
	 * @param  string      $owner   Repo owner
	 * @param  string      $repo    Repo name
	 * @param  string      $ref     Tag or branch name
	 * @param  string|null $token   Optional token
	 * @param  string      $dest    Destination file path
	 * @return array                ['success' => bool, 'message' => string]
	 */
	private function gitDownloadArchive($gitHost, $baseUrl, $owner, $repo, $ref, $token, $dest)
	{
		if ($gitHost === 'gitlab') {
			if (empty($baseUrl)) {
				return array('success' => false, 'message' => 'GitLab base URL is missing');
			}
			$project = ltrim(($owner === '' ? '' : $owner.'/').$repo, '/');
			$base = rtrim($baseUrl, '/').'/api/v4/projects/'.rawurlencode($project).'/repository/archive.tar.gz?sha=';

			// DMM stores versions with the leading "v" stripped and the install
			// handler re-adds it — but tagging conventions differ: GitHub repos
			// often tag "v1.2.3" while self-hosted GitLab modules (e.g. open-dsi's
			// banking4dolibarr) tag "14.0.103" with no prefix. Trying a single ref
			// 404s for whichever convention we guessed wrong. So try the ref as-is,
			// then with the "v" toggled.
			$refs = array($ref);
			if (preg_match('/^v\d/i', $ref)) {
				$refs[] = ltrim($ref, 'vV');           // v14.0.103 -> 14.0.103
			} elseif (preg_match('/^\d/', $ref)) {
				$refs[] = 'v'.$ref;                    // 14.0.103  -> v14.0.103
			}

			$result = null;
			foreach ($refs as $candidate) {
				$result = $this->streamDownload($base.rawurlencode($candidate), $token, $dest, 'gitlab');
				if (!empty($result['success'])) {
					return $result;
				}
			}
			return $result;
		}
		return $this->downloadTarball($owner, $repo, $ref, $token, $dest);
	}

	/**
	 * Stream a URL to disk with curl, reusing the CURLOPT_FILE pattern.
	 * Used by the GitLab tarball path. GitHub keeps the existing downloadTarball()
	 * implementation to preserve its test surface.
	 *
	 * @param  string      $url     Full URL
	 * @param  string|null $token   Optional token for auth header
	 * @param  string      $dest    Destination file path
	 * @param  string      $host    Host label for error messages
	 * @return array                ['success'=>bool, 'message'=>string]
	 */
	private function streamDownload($url, $token, $dest, $host)
	{
		$dir = dirname($dest);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$fp = fopen($dest, 'wb');
		if (!$fp) {
			return array('success' => false, 'message' => 'Cannot create temp file: '.$dest);
		}

		$headers = array('User-Agent: DMM/1.0');
		if (!empty($token)) {
			if ($host === 'gitlab') {
				$headers[] = 'PRIVATE-TOKEN: '.$token;
			} else {
				$headers[] = 'Authorization: Bearer '.$token;
			}
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT => 180,
		));
		$ok = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if (!$ok || $code !== 200) {
			@unlink($dest);
			return array('success' => false, 'message' => ucfirst($host).' download failed: HTTP '.$code);
		}
		return array('success' => true, 'message' => 'Downloaded to '.$dest);
	}
}
