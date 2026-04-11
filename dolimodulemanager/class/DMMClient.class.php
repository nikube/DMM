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
		$token = $this->resolveToken($module_id, $token);
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

		// Dev channel: short-circuit to branch HEAD SHA tracking. Only honored when the
		// global developer mode is on AND the per-module row is set to channel='dev'.
		if ($this->standalone && function_exists('dmm_is_dev_mode') && dmm_is_dev_mode()) {
			if ($modRow && ($modRow->channel ?? 'stable') === 'dev' && !empty($modRow->branch_dev)) {
				return $this->checkDevBranchUpdate($module_id, $owner, $repoName, $modRow->branch_dev, $token, $gitHost, $gitBaseUrl);
			}
		}

		// Fetch releases (host-aware). For hosts that don't expose /releases (e.g.
		// self-hosted GitLab with the endpoint admin-locked, or repos that never
		// tagged a release), fall back to branch-HEAD tracking so the module is
		// still usable for install/update.
		$releasesResult = $this->gitListReleases($gitHost, $gitBaseUrl, $owner, $repoName, $token);
		$releases = array();
		$releasesReachable = ($releasesResult !== null && $releasesResult['code'] === 200);
		if ($releasesReachable) {
			$decoded = json_decode($releasesResult['body'], true);
			if (is_array($decoded)) {
				$releases = $decoded;
			}
		}
		if (!$releasesReachable) {
			// Branch-HEAD fallback: read the row's declared branch (defaults to main/master)
			// and use its SHA as a synthetic "release". This is the same mechanism as the
			// dev channel, applied automatically when no releases are visible.
			$fallbackBranch = ($modRow && !empty($modRow->branch)) ? $modRow->branch : ($gitHost === 'gitlab' ? 'master' : 'main');
			$fallbackSha = $this->fetchBranchSha($owner, $repoName, $fallbackBranch, $token, $gitHost, $gitBaseUrl);
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
		$manifest = $this->gitFetchManifest($gitHost, $gitBaseUrl, $owner, $repoName, $modRow ? $modRow->branch : null, $token, $module_id);

		// Get current environment
		$dolibarrVersion = DOL_VERSION;
		$phpVersion = PHP_VERSION;
		$installedVersion = $this->getInstalledVersion($module_id);

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
			// "branch:{sha12}" pseudo-version that version_compare will treat as a
			// string — the update-available check below handles it specially.
			if (!empty($release['_synthetic_sha'])) {
				$shortSha = substr($release['_synthetic_sha'], 0, 12);
				$latestVersion = 'branch:'.$shortSha;
				$latestCompatible = $latestVersion;
				$latestChangelog = '';
				$latestTag = $release['tag_name'];
				$latestVerified = false;
				$usedBranchFallback = true;
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
			if ($usedBranchFallback) {
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
			$this->updateModuleCache($module_id, $cacheUpdate);

			// Auto-detect installed status from filesystem
			if ($installedVersion !== null) {
				$this->syncInstalledStatus($module_id, $installedVersion);
			}
		}

		return $result;
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

		$token = $this->resolveToken($module_id, $token);
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

		$downloadResult = $this->gitDownloadArchive($gitHost, $gitBaseUrl, $owner, $repoName, $tag, $token, $tarGzPath);
		if (!$downloadResult['success']) {
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			return array('success' => false, 'message' => 'Download failed: '.$downloadResult['message'], 'backup_path' => $backupPath);
		}

		// Extract
		$extractDir = $tempDir.'/dmm_extract_'.uniqid();
		$extractResult = $this->extractTarball($tarGzPath, $extractDir);
		if (!$extractResult['success']) {
			@unlink($tarGzPath);
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			return array('success' => false, 'message' => 'Extraction failed: '.$extractResult['message'], 'backup_path' => $backupPath);
		}

		// Find the actual module content (GitHub/GitLab tarballs wrap content in one
		// top-level directory). If a subdir is declared (e.g. monorepo entry), look
		// inside wrapper/{subdir}/.
		$sourceDir = $this->findModuleRoot($extractDir, $module_id, $subdir);
		if ($sourceDir === false) {
			$this->cleanupDir($extractDir);
			@unlink($tarGzPath);
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			return array('success' => false, 'message' => 'Could not find module content in archive', 'backup_path' => $backupPath);
		}

		// Replace module directory
		if ($isUpdate) {
			// For updates: overwrite in-place (safe for self-updates where dir can't be deleted)
			$copyResult = $this->recursiveCopy($sourceDir, $targetDir);
			$this->cleanupDir($sourceDir);
			if (!$copyResult) {
				$detail = $this->error ?: 'unknown error';
				$detail .= ' | src_exists='.var_export(is_dir($sourceDir), true);
				$detail .= ' | dest_writable='.var_export(is_writable($targetDir), true);
				$detail .= ' | dest_owner='.(@fileowner($targetDir) ?: '?');
				$detail .= ' | php_user='.(function_exists('dmm_get_php_user') ? dmm_get_php_user('?') : '?');
				if ($backupPath) {
					$this->restoreFromBackup($module_id, $backupPath);
				}
				return array('success' => false, 'message' => 'Failed to copy module files to '.$targetDir.' ('.$detail.')', 'backup_path' => $backupPath);
			}
		} else {
			// Fresh install — move into place
			if (!@rename($sourceDir, $targetDir)) {
				@mkdir($targetDir, 0755, true);
				$this->recursiveCopy($sourceDir, $targetDir);
				$this->cleanupDir($sourceDir);
			}
		}

		// Verify: check descriptor exists
		$descriptorFound = $this->findDescriptor($targetDir);
		if (!$descriptorFound) {
			// Rollback
			dol_delete_dir_recursive($targetDir);
			if ($isUpdate && $backupPath) {
				$this->restoreFromBackup($module_id, $backupPath);
			}
			$this->cleanupDir($extractDir);
			@unlink($tarGzPath);
			return array('success' => false, 'message' => 'Module descriptor not found after extraction', 'backup_path' => $backupPath);
		}

		// Cleanup temp files
		$this->cleanupDir($extractDir);
		@unlink($tarGzPath);
		$tarPath = preg_replace('/\.gz$/', '', $tarGzPath);
		if (file_exists($tarPath)) {
			@unlink($tarPath);
		}

		// Update registry if standalone. For the dev channel we store the resolved
		// commit SHA so future checks compare against an immutable identifier.
		if ($channel === 'dev') {
			$sha = $this->fetchBranchSha($owner, $repoName, $tag, $token, $gitHost, $gitBaseUrl);
			$newVersion = $sha ? 'dev:'.substr($sha, 0, 12) : 'dev:'.$tag;
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

		// Check each repo for dmm.json and dmmhub.json
		$scanReport['repos_hub'] = array();
		foreach ($repos as $repoData) {
			$fullName = $repoData['full_name'] ?? '';
			if (empty($fullName)) {
				continue;
			}

			$scanReport['repos_visible'][] = $fullName;

			list($owner, $repoName) = explode('/', $fullName, 2);

			// Check for dmm.json (module)
			$manifest = $this->fetchManifest($owner, $repoName, $token);
			if ($manifest !== null) {
				$manifest['github_repo'] = $fullName;
				$modules[] = $manifest;
				$scanReport['repos_dmm'][] = $fullName;
				continue;
			}

			// Check for dmmhub.json (hub)
			$hubResult = $this->githubApiCall('/repos/'.$owner.'/'.$repoName.'/contents/dmmhub.json', $token);
			if ($hubResult !== null && $hubResult['code'] === 200) {
				$scanReport['repos_hub'][] = $fullName;
				continue;
			}

			$scanReport['repos_other'][] = $fullName;
		}

		return $modules;
	}

	/**
	 * Discover and register all DMM-compatible modules accessible via a token.
	 * Scans repos for dmm.json, registers new ones in llx_dmm_module.
	 *
	 * @param  int    $tokenRowId  Token row ID in llx_dmm_token
	 * @param  string $plainToken  Decrypted GitHub token
	 * @return array               ['discovered' => int, 'skipped' => int, 'errors' => string[]]
	 */
	public function discoverModules($tokenRowId, $plainToken)
	{
		$result = array('discovered' => 0, 'skipped' => 0, 'errors' => array(), 'scan' => array(), 'hubs_found' => array());

		$scanReport = null;
		$modules = $this->listAvailableModules($plainToken, $scanReport);
		$result['scan'] = $scanReport;

		// Auto-register discovered hubs
		if (!empty($scanReport['repos_hub']) && function_exists('dmm_get_hubs') && function_exists('dmm_save_hubs')) {
			$hubs = dmm_get_hubs();
			$existingUrls = array_map(function ($h) { return $h['url']; }, $hubs);

			foreach ($scanReport['repos_hub'] as $hubRepo) {
				$hubUrl = 'https://api.github.com/repos/'.$hubRepo.'/contents/dmmhub.json';
				if (!in_array($hubUrl, $existingUrls)) {
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

			// Check if already registered (by module_id OR by github_repo)
			$existing = new DMMModule($this->db);
			if ($existing->fetch(0, $module_id) > 0) {
				$result['skipped']++;
				continue;
			}
			$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($github_repo)."'";
			$resCheck = $this->db->query($sqlCheck);
			if ($resCheck && $this->db->num_rows($resCheck) > 0) {
				$result['skipped']++;
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

			// Auto-detect if installed
			$localDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (is_dir($localDir) && is_dir($localDir.'/core/modules')) {
				$mod->installed = 1;
				$descFiles = glob($localDir.'/core/modules/mod*.class.php');
				if (!empty($descFiles)) {
					$content = file_get_contents($descFiles[0]);
					if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $vm)) {
						$mod->installed_version = $vm[1];
					}
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
		if (!empty($token)) {
			$headers[] = 'Authorization: Bearer '.$token;
		}
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
		));
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($response === false || $httpCode !== 200) {
			// If GitHub API URL, try with tokens
			if ($httpCode === 404 || $httpCode === 401) {
				if ($this->standalone && empty($token)) {
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
			'registered' => 0, 'matched' => 0, 'needs_token' => 0, 'skipped' => 0, 'errors' => array(),
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

		foreach ($hub['modules'] as $entry) {
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
				$manifest = $this->fetchManifest($owner, $repoName, null);
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

			// Skip if already registered (by module_id OR by github_repo)
			$existing = new DMMModule($this->db);
			if ($existing->fetch(0, $module_id) > 0) {
				$report['skipped']++;
				continue;
			}
			// Also check by github_repo to prevent duplicates with different module_id
			$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($repoPath)."'";
			$resCheck = $this->db->query($sqlCheck);
			if ($resCheck && $this->db->num_rows($resCheck) > 0) {
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

			// Auto-detect if installed
			$localDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (is_dir($localDir) && is_dir($localDir.'/core/modules')) {
				$mod->installed = 1;
				$descFiles = glob($localDir.'/core/modules/mod*.class.php');
				if (!empty($descFiles)) {
					$content = file_get_contents($descFiles[0]);
					if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $vm)) {
						$mod->installed_version = $vm[1];
					}
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
			if (function_exists('dmm_get_hubs') && function_exists('dmm_save_hubs')) {
				$existingHubs = dmm_get_hubs();
				$existingUrls = array_map(function ($h) { return $h['url']; }, $existingHubs);

				foreach ($hub['hubs'] as $subHubUrl) {
					if (!is_string($subHubUrl) || isset($visitedHubs[$subHubUrl])) {
						continue;
					}
					$visitedHubs[$subHubUrl] = true;

					if (!in_array($subHubUrl, $existingUrls)) {
						$existingHubs[] = array('url' => $subHubUrl, 'enabled' => 0);
						$existingUrls[] = $subHubUrl;
					}
					// Import modules from sub-hub
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

		$data = json_decode($result['body'], true);
		if (!isset($data['content'])) {
			return null;
		}

		$content = base64_decode($data['content']);
		$manifest = json_decode($content, true);

		if (!is_array($manifest) || !isset($manifest['schema_version'])) {
			return null;
		}

		// Only parse schema versions we understand — forward compatible
		// Exception: always allow DMM's own manifest (self-update must never be blocked by a schema change)
		if ($manifest['schema_version'] !== '1' && $module_id !== 'dolimodulemanager') {
			$this->error = 'Unsupported dmm.json schema_version: '.$manifest['schema_version'].'. Update DMM to the latest version.';
			return null;
		}

		return $manifest;
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
		$sql = "SHOW TABLES LIKE '".$this->db->escape($fullName)."'";
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
			return $repo;
		}

		if ($this->standalone) {
			$sql = "SELECT github_repo FROM ".$this->db->prefix()."dmm_module";
			$sql .= " WHERE module_id = '".$this->db->escape($module_id)."'";
			$sql .= " LIMIT 1";

			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				return $obj->github_repo;
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
				return $data['repository'];
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
	private function getInstalledVersion($module_id)
	{
		$customDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
		if (!is_dir($customDir)) {
			return null;
		}

		$descriptorFile = $this->findDescriptor($customDir);
		if (!$descriptorFile) {
			return null;
		}

		// Parse version from descriptor without including the file
		$content = file_get_contents($descriptorFile);
		if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Find the module descriptor file in a directory
	 *
	 * @param  string       $dir Directory to search
	 * @return string|false      Path to descriptor or false
	 */
	private function findDescriptor($dir)
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
			$backup->fk_dmm_module = ($modResult > 0) ? $mod->id : 0;
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
			return;
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
		if ($mod->fetch(0, $module_id) > 0) {
			$mod->installed_version = $version;
			$mod->installed = 1;
			$mod->invalidateCache();
			global $user;
			$mod->update($user);
		}
	}

	/**
	 * Sanitize module ID
	 *
	 * @param  string       $id Module ID
	 * @return string|false
	 */
	private function sanitizeModuleId($id)
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
		if ($this->standalone) {
			$row = $this->loadModuleRow($module_id);
			if ($row && !empty($row->installed_version) && strpos($row->installed_version, 'dev:') === 0) {
				$registryInstalled = $row->installed_version;
			}
		}
		$compareInstalled = $registryInstalled ?: $installedVersion;
		$updateAvailable = ($compareInstalled !== $latestVersion);

		$result = array(
			'update_available'         => $updateAvailable,
			'installed_version'        => $compareInstalled,
			'latest_version'           => $latestVersion,
			'latest_compatible_version' => $latestVersion,
			'changelog'                => '',
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
		curl_close($ch);

		if ($body === false || $httpCode !== 200) {
			$this->error = 'Failed to fetch community YAML: HTTP '.$httpCode;
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
					if ($v === '') {
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
			if ($value === '') {
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
	 *   - status == 'enabled'
	 *   - git URL parses to a supported host (github.com or known GitLab host)
	 * Monorepo entries (git URL contains '/tree/{branch}/{subdir}') are registered
	 * with a `subdir` populated so install extracts the subdirectory from the wrapper.
	 *
	 * Stale v1.6.0 rows are healed: when dedupe matches an existing row whose source
	 * is already `dolibarr-community`, the row is UPDATED from the current YAML entry
	 * (in-place heal) instead of skipped.
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

			// Status filter: only enabled modules (drops "soon", "deprecated", etc.)
			if (isset($entry['status']) && strtolower(trim((string) $entry['status'])) !== 'enabled') {
				$report['filtered']++;
				continue;
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

			// Dedupe by module_id first, then by github_repo. If a match has source
			// 'dolibarr-community', we UPDATE it in-place to heal stale v1.6.0 rows.
			$existing = new DMMModule($this->db);
			$found = ($existing->fetch(0, $module_id) > 0);
			if (!$found) {
				$sqlCheck = "SELECT rowid FROM ".$this->db->prefix()."dmm_module WHERE github_repo = '".$this->db->escape($repoPath)."'";
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
				$existing->git_host = $gitHost;
				$existing->git_base_url = $gitBaseUrl;
				$existing->subdir = $subdir;
				if (!empty($entry['current_version'])) {
					$existing->cache_latest_version = (string) $entry['current_version'];
					$existing->cache_latest_compatible = (string) $entry['current_version'];
				}
				// Clear any stale error left over from "monorepo install not supported"
				// since install is now wired for subdirs.
				$existing->cache_last_error = null;
				if ($existing->update($user) > 0) {
					$report['updated']++;
					if (!empty($subdir)) {
						$report['monorepo']++;
					}
				} else {
					$report['errors'][] = $moduleName.': heal failed — '.$existing->error;
				}
				continue;
			}

			// Fresh row
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
			$mod->channel = 'stable';
			if (!empty($entry['current_version'])) {
				$mod->cache_latest_version = (string) $entry['current_version'];
				$mod->cache_latest_compatible = (string) $entry['current_version'];
			}
			if (!empty($subdir)) {
				$report['monorepo']++;
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
	 *                             'subdir'=>string|null]
	 *                             or null if the host is unsupported.
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
			);
		}

		// Known GitLab hosts. The "owner" may contain slashes (group namespaces).
		$knownGitlab = array('inligit.fr');
		if (!in_array($host, $knownGitlab, true)) {
			return null;
		}
		return array(
			'host' => 'gitlab',
			'base_url' => $baseUrl,
			'project' => $projectPath,
			'owner' => $owner,
			'repo' => $repo,
			'subdir' => $subdir,
		);
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
			$ref = !empty($branch) ? $branch : 'main';
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
		// GitHub — delegate to the existing public method
		return $this->fetchManifest($owner, $repo, $token, $module_id);
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
			$url = rtrim($baseUrl, '/').'/api/v4/projects/'.rawurlencode($project).'/repository/archive.tar.gz?sha='.rawurlencode($ref);
			return $this->streamDownload($url, $token, $dest, 'gitlab');
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
