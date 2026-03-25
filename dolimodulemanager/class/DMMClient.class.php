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

		// Fetch releases
		$releasesResult = $this->githubApiCall('/repos/'.$owner.'/'.$repoName.'/releases', $token);
		if ($releasesResult === null || $releasesResult['code'] !== 200) {
			$this->error = 'GitHub API error: '.($releasesResult['body'] ?? 'connection failed');
			if ($this->standalone) {
				$this->updateModuleCache($module_id, array('error' => $this->error));
			}
			return null;
		}

		$releases = json_decode($releasesResult['body'], true);
		if (!is_array($releases)) {
			$this->error = 'Invalid response from GitHub releases API';
			return null;
		}

		// Fetch manifest
		$manifest = $this->fetchManifest($owner, $repoName, $token);

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

		foreach ($releases as $release) {
			if (!empty($release['draft']) || !empty($release['prerelease'])) {
				continue;
			}

			$tag = $release['tag_name'] ?? '';
			$version = ltrim($tag, 'vV');
			if (empty($version)) {
				continue;
			}

			// Track absolute latest
			if ($latestVersion === null || version_compare($version, $latestVersion, '>')) {
				$latestVersion = $version;
			}

			// Get compatibility data for this release
			$releaseBody = $release['body'] ?? '';
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
			$updateAvailable = version_compare($latestCompatible, $installedVersion, '>');
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

		// Update cache if standalone
		if ($this->standalone) {
			$this->updateModuleCache($module_id, array(
				'latest_version'    => $latestVersion,
				'latest_compatible' => $latestCompatible,
				'changelog'         => $latestChangelog,
				'etag'              => $releasesResult['etag'] ?? null,
			));
		}

		return $result;
	}

	/**
	 * Download and install/update a module.
	 *
	 * @param  string      $module_id Module identifier
	 * @param  string      $tag       Git tag to install (e.g., 'v1.3.0')
	 * @param  string|null $token     GitHub token
	 * @param  string|null $repo      GitHub repo (owner/repo)
	 * @return array                  Result: ['success' => bool, 'message' => string, 'backup_path' => string|null]
	 */
	public function installOrUpdate($module_id, $tag, $token = null, $repo = null)
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

		$downloadResult = $this->downloadTarball($owner, $repoName, $tag, $token, $tarGzPath);
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

		// Find the actual module content (GitHub adds a wrapper directory)
		$sourceDir = $this->findModuleRoot($extractDir, $module_id);
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
			$copyResult = dolCopyDir($sourceDir, $targetDir, '0', 1);
			$this->cleanupDir($sourceDir);
			if ($copyResult < 0) {
				if ($backupPath) {
					$this->restoreFromBackup($module_id, $backupPath);
				}
				return array('success' => false, 'message' => 'Failed to copy module files to '.$targetDir, 'backup_path' => $backupPath);
			}
		} else {
			// Fresh install — move into place
			if (!rename($sourceDir, $targetDir)) {
				dolCopyDir($sourceDir, $targetDir, '0', 1);
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

		// Update registry if standalone
		$newVersion = ltrim($tag, 'vV');
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
	public function listAvailableModules($token = null)
	{
		$modules = array();

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

		// Check each repo for dmm.json
		foreach ($repos as $repoData) {
			$fullName = $repoData['full_name'] ?? '';
			if (empty($fullName)) {
				continue;
			}

			list($owner, $repoName) = explode('/', $fullName, 2);
			$manifest = $this->fetchManifest($owner, $repoName, $token);
			if ($manifest !== null) {
				$manifest['github_repo'] = $fullName;
				$modules[] = $manifest;
			}
		}

		return $modules;
	}

	/**
	 * Parse the dmm.json manifest from a repository.
	 *
	 * @param  string      $owner Repo owner
	 * @param  string      $repo  Repo name
	 * @param  string|null $token GitHub token
	 * @return array|null         Parsed manifest or null if not found
	 */
	public function fetchManifest($owner, $repo, $token)
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

			$tarPath = preg_replace('/\.gz$/', '', $tarGzPath);
			$tar = new PharData($tarPath);
			$tar->extractTo($extractTo);

			return array('success' => true, 'message' => 'Extracted to '.$extractTo);
		} catch (Exception $e) {
			return array('success' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * Find the module root directory inside an extracted GitHub tarball.
	 * GitHub tarballs have a wrapper directory: owner-repo-hash/
	 *
	 * Supports two repo layouts:
	 * 1. Module at root:     owner-repo-hash/core/modules/modXxx.class.php
	 * 2. Module in subfolder: owner-repo-hash/mymodule/core/modules/modXxx.class.php
	 *
	 * @param  string       $extractDir Base extraction directory
	 * @param  string       $module_id  Expected module ID
	 * @return string|false             Path to module root or false
	 */
	private function findModuleRoot($extractDir, $module_id)
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

		// GitHub tarballs always have exactly one top-level wrapper dir: owner-repo-hash/
		$wrapperDir = $extractDir.'/'.$dirs[0];

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
}
