<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/dolimodulemanager.lib.php
 * \ingroup dolimodulemanager
 * \brief   Library functions for DoliModuleManager
 */

/**
 * Prepare admin pages header tabs
 *
 * @param  string $active Active tab identifier
 * @return array<array{string,string,string}>
 */
function dolimodulemanagerAdminPrepareHead($active = 'dashboard')
{
	global $langs, $conf, $db;

	$langs->load('dolimodulemanager@dolimodulemanager');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/index.php', 1);
	$head[$h][1] = $langs->trans('DMMDashboard');
	$head[$h][2] = 'dashboard';
	$h++;

	// One way in for anything not on this Dolibarr yet, whatever its origin. The
	// former Marketplace and Purchases tabs split on free-vs-paid, a boundary that
	// never held — the order history lists only paid modules, so free ones had to
	// be added by URL from the "purchases" tab anyway.
	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/add.php', 1);
	$head[$h][1] = $langs->trans('DMMAddModule');
	$head[$h][2] = 'add';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('DMMSettingsTab');
	$head[$h][2] = 'settings';
	$h++;

	// Advanced and Sources are developer territory — cache internals, hub and token
	// plumbing, the local scan. Hidden unless developer mode is on, so the default
	// install shows the three tabs that cover normal use. The toggle itself lives
	// in Settings, which is always visible.
	if (dmm_is_dev_mode()) {
		$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/advanced.php', 1);
		$head[$h][1] = $langs->trans('DMMAdvancedTab');
		$head[$h][2] = 'advanced';
		$h++;

		$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/sources.php', 1);
		$head[$h][1] = $langs->trans('DMMSourcesTab');
		$head[$h][2] = 'sources';
		$h++;
	}

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager', 'remove');

	return $head;
}

/**
 * Check a DMM permission while treating Dolibarr admins as full DMM admins.
 *
 * @param  string $right Permission leaf: read, write or admin
 * @return bool
 */
function dmm_user_can($right)
{
	global $user;

	return !empty($user->admin) || $user->hasRight('dolimodulemanager', $right);
}

/**
 * Refuse access unless the current user has the requested DMM permission.
 *
 * @param  string $right Permission leaf: read, write or admin
 * @return void
 */
function dmm_require_right($right)
{
	if (!dmm_user_can($right)) {
		accessforbidden();
	}
}

/**
 * Sanitize a module ID to prevent path traversal.
 * Only allows lowercase letters, numbers and underscores.
 *
 * @param  string      $id Raw module ID
 * @return string|false    Sanitized ID or false if invalid
 */
function dmm_sanitize_module_id($id)
{
	$id = trim(strtolower($id));
	if (!preg_match('/^[a-z0-9_]+$/', $id)) {
		return false;
	}
	return $id;
}

/**
 * Derive the module id for a repo entry parsed by DMMClient::parsePublicRepoInput().
 *
 * A plain repo is named after itself, but a monorepo holds one module per
 * subdirectory (DoliCloud/DoliMods, Dolibarr/dolibarr-community-modules): there
 * the last path segment is the module, and the repo name says nothing. Getting
 * this wrong is not cosmetic — the module is unpacked into custom/{module_id},
 * and Dolibarr resolves a module's own includes through that folder name, so a
 * repo-named folder yields a module that lists but fatals on use.
 *
 * @param  array       $parsed   Descriptor from parsePublicRepoInput()
 * @param  string|null $manifest Module id declared by dmm.json, if any (wins)
 * @return string                Sanitized module id (never empty)
 */
function dmm_module_id_from_parsed($parsed, $manifest = null)
{
	if (!empty($manifest)) {
		$fromManifest = dmm_sanitize_module_id($manifest);
		if ($fromManifest !== false && $fromManifest !== '') {
			return $fromManifest;
		}
	}

	// Monorepo: the module lives at .../tree/{branch}/{subdir}, so its own
	// directory name is the id — "htdocs/stancerdolicloud" -> "stancerdolicloud".
	$candidate = $parsed['repo'] ?? '';
	if (!empty($parsed['subdir'])) {
		$leaf = basename(rtrim((string) $parsed['subdir'], '/'));
		if ($leaf !== '' && $leaf !== '.' && $leaf !== '..') {
			$candidate = $leaf;
		}
	}

	// sanitize rejects anything outside [a-z0-9_], so strip separators first the
	// way the callers this replaces did (hyphens and dots are common in repo names).
	return strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $candidate));
}

/**
 * Apply the branch carried by a /tree/{branch}/... URL to a module row.
 *
 * Such a URL points at a branch, not a tag, and monorepos are typically
 * published without releases at all — so a row left on the stable channel would
 * hunt for tags that do not exist and fail the install with a bare HTTP 404.
 *
 * @param  DMMModule $mod    Row being built (modified in place)
 * @param  array     $parsed Descriptor from parsePublicRepoInput()
 * @return void
 */
function dmm_apply_parsed_branch($mod, $parsed)
{
	$branch = trim((string) ($parsed['branch'] ?? ''));
	if ($branch === '') {
		return;
	}
	$mod->branch = $branch;
	$mod->branch_dev = $branch;
	$mod->channel = 'dev';
}

/**
 * Get the username of the current PHP process. Safe across all PHP versions.
 *
 * @param  string $fallback Default if detection fails
 * @return string
 */
function dmm_get_php_user($fallback = 'www-data')
{
	if (function_exists('posix_geteuid')) {
		$pwuid = @posix_getpwuid(@posix_geteuid());
		if (is_array($pwuid) && !empty($pwuid['name'])) {
			return $pwuid['name'];
		}
	}
	$user = @get_current_user();
	return !empty($user) ? $user : $fallback;
}

/**
 * Get the owner name of a file/directory. Safe across all PHP versions.
 *
 * @param  string $path     File or directory path
 * @param  string $fallback Default if detection fails
 * @return string
 */
function dmm_get_file_owner($path, $fallback = '?')
{
	if (function_exists('posix_getpwuid')) {
		$uid = @fileowner($path);
		if ($uid !== false) {
			$pwuid = @posix_getpwuid($uid);
			if (is_array($pwuid) && !empty($pwuid['name'])) {
				return $pwuid['name'];
			}
		}
	}
	return $fallback;
}

/**
 * Check whether PHP can safely back up and replace an installed module tree.
 *
 * Dolibarr's native ZIP deployer intentionally creates module files as 0444.
 * Those files do not need to be writable to replace a module: on Unix, removal
 * and rename are controlled by the containing directory. Requiring
 * is_writable() on every file therefore rejects perfectly deployable modules.
 * Files only need to be readable for DMM's backup; directories must be readable
 * and writable so their entries can be traversed and removed.
 *
 * @param  string      $dir Installed module directory
 * @return string|null      First actionable problem, or null when replaceable
 */
function dmm_check_module_replace_permissions($dir)
{
	$parent = dirname($dir);
	if (!is_dir($parent) || !is_writable($parent)) {
		return $parent.' is not writable';
	}
	if (!is_dir($dir)) {
		return null;
	}
	if (!is_readable($dir) || !is_writable($dir) || !is_executable($dir)) {
		return $dir.' is not readable/writable/traversable';
	}

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $item) {
			$path = $item->getPathname();
			if ($item->isDir()) {
				if (!is_readable($path) || !is_writable($path) || !is_executable($path)) {
					return $path.' is not readable/writable/traversable';
				}
			} elseif (!is_readable($path)) {
				return $path.' is not readable';
			}
		}
	} catch (UnexpectedValueException $e) {
		return $e->getMessage();
	}

	return null;
}

/**
 * Check if a module ID is a core Dolibarr module that must not be overwritten.
 *
 * @param  string $id Module ID
 * @return bool       True if core module
 */
function dmm_is_core_module($id)
{
	static $coreModules = null;

	if ($coreModules === null) {
		$coreModules = array();
		$coreDir = DOL_DOCUMENT_ROOT.'/core/modules/';
		if (is_dir($coreDir)) {
			$files = glob($coreDir.'mod*.class.php');
			foreach ($files as $f) {
				$className = basename($f, '.class.php');
				$modName = strtolower(preg_replace('/^mod/i', '', $className));
				if ($modName !== '') {
					$coreModules[$modName] = true;
				}
			}
		}
	}

	return isset($coreModules[strtolower($id)]);
}

/**
 * Get a DMM setting value from llx_dmm_setting.
 *
 * @param  string      $name    Setting key
 * @param  string|null $default Default value if not found
 * @return string|null
 */
function dmm_get_setting($name, $default = null)
{
	global $db;

	$sql = "SELECT value FROM ".$db->prefix()."dmm_setting WHERE name = '".$db->escape($name)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		return $obj->value;
	}
	return $default;
}

/**
 * Set a DMM setting value in llx_dmm_setting.
 *
 * @param  string $name  Setting key
 * @param  string $value Setting value
 * @return int           1 on success, -1 on error
 */
function dmm_set_setting($name, $value)
{
	global $db;

	$sql = "SELECT rowid FROM ".$db->prefix()."dmm_setting WHERE name = '".$db->escape($name)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$sql = "UPDATE ".$db->prefix()."dmm_setting SET value = '".$db->escape($value)."' WHERE name = '".$db->escape($name)."'";
	} else {
		$sql = "INSERT INTO ".$db->prefix()."dmm_setting (name, value) VALUES ('".$db->escape($name)."', '".$db->escape($value)."')";
	}

	$resql = $db->query($sql);
	return $resql ? 1 : -1;
}

/**
 * Should this module be followed at branch HEAD rather than at a release tag?
 *
 * Two distinct cases used to be conflated under channel='dev':
 *
 *  - The user put a module on a dev channel to track unreleased work. That is a
 *    developer-mode affordance, and it stays gated behind developer mode.
 *  - The module is *only* distributed from a branch, because its repository
 *    publishes no releases at all. Every module in the Dolibarr community index
 *    is like this. Nothing about it is a developer preference, so hiding it
 *    behind developer mode left those modules resolving to a tag that does not
 *    exist (e.g. v1.0.3 on a repo with zero releases).
 *
 * The second case is recognised by source: a community row declares its branch
 * in the index itself, so it is branch-backed whatever the UI is set to.
 *
 * @param  DMMModule|object $modRow Module row
 * @return bool                     True when branch HEAD should be followed
 */
function dmm_module_tracks_branch($modRow)
{
	if (empty($modRow) || empty($modRow->branch_dev)) {
		return false;
	}
	if (($modRow->channel ?? 'stable') !== 'dev') {
		return false;
	}

	// Branch-backed by distribution, not by preference.
	if (($modRow->source ?? '') === 'dolibarr-community') {
		return true;
	}

	// Same for a monorepo subdirectory: a repo holding many modules publishes no
	// per-module tag, so the branch is the only ref there is — whatever the UI
	// preference says. Without this the row falls back to the stable channel and
	// the install looks for releases that were never cut.
	if (!empty($modRow->subdir)) {
		return true;
	}

	return function_exists('dmm_is_dev_mode') && dmm_is_dev_mode();
}

/**
 * Count settings whose key matches a LIKE pattern.
 *
 * Used to report how many entries a cache holds without loading any of them.
 *
 * @param  string $pattern SQL LIKE pattern, e.g. 'manifest_cache_%'
 * @return int             Number of matching rows
 */
function dmm_get_setting_count_like($pattern)
{
	global $db;

	$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."dmm_setting WHERE name LIKE '".$db->escape($pattern)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		return (int) $obj->nb;
	}
	return 0;
}

/**
 * Check whether the global "developer mode" toggle is enabled.
 * Gates the per-module dev channel selector and other developer affordances.
 *
 * @return bool
 */
function dmm_is_dev_mode()
{
	return dmm_get_setting('dev_mode_enabled', '0') === '1';
}

/**
 * Check whether the running Dolibarr version satisfies a DoliStore-style
 * compatibility range. DoliStore exposes constraints as "V14"/"V23" tokens
 * (sometimes with leading lowercase or trailing minor numbers, sometimes
 * inverted by sloppy vendors — be tolerant).
 *
 * Returns one of:
 *   'ok'     — current version is inside [min, max]
 *   'below'  — current version is older than min (module needs newer Dolibarr)
 *   'above'  — current version is newer than max (module not certified yet)
 *   'unknown' — range can't be parsed (no opinion, treat as "probably ok")
 *
 * @param  string|null $min Raw min token (e.g. "V14")
 * @param  string|null $max Raw max token (e.g. "V23")
 * @param  string|null $current Optional override (defaults to DOL_VERSION)
 * @return string
 */
function dmm_check_dolibarr_compat($min, $max, $current = null)
{
	if ($current === null) {
		$current = defined('DOL_VERSION') ? DOL_VERSION : '';
	}
	if ($current === '') {
		return 'unknown';
	}
	$currentMajor = (int) preg_replace('/^(\d+).*/', '$1', $current);
	if ($currentMajor <= 0) {
		return 'unknown';
	}

	$normalize = function ($v) {
		if ($v === null || $v === '' || strtolower((string) $v) === 'unknown') {
			return null;
		}
		if (preg_match('/(\d+)/', (string) $v, $m)) {
			return (int) $m[1];
		}
		return null;
	};
	$minMajor = $normalize($min);
	$maxMajor = $normalize($max);

	// Tolerate inverted ranges (vendor typo): swap them silently.
	if ($minMajor !== null && $maxMajor !== null && $minMajor > $maxMajor) {
		$tmp = $minMajor;
		$minMajor = $maxMajor;
		$maxMajor = $tmp;
	}

	if ($minMajor === null && $maxMajor === null) {
		return 'unknown';
	}
	if ($minMajor !== null && $currentMajor < $minMajor) {
		return 'below';
	}
	if ($maxMajor !== null && $currentMajor > $maxMajor) {
		return 'above';
	}
	return 'ok';
}

/**
 * Get the Dolibarr community YAML import config (URL + enabled flag).
 *
 * @return array{url:string,enabled:bool}
 */
function dmm_get_community_yaml_config()
{
	return array(
		'url' => (string) dmm_get_setting('community_yaml_url', 'https://raw.githubusercontent.com/Dolibarr/dolibarr-community-modules/main/index.yaml'),
		'enabled' => dmm_get_setting('community_yaml_enabled', '0') === '1',
	);
}

/**
 * Auto-check all modules for updates if cache is stale.
 * Called on page load when auto_check setting is enabled.
 * Only checks modules whose cache has expired.
 *
 * @param  int $maxChecks Maximum modules to check during this request (0 = no limit)
 * @return int Number of modules checked (0 if nothing to do)
 */
function dmm_auto_check_updates($maxChecks = 0)
{
	global $db;

	if (dmm_get_setting('auto_check', '1') !== '1') {
		return 0;
	}

	dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
	dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
	dol_include_once('/dolimodulemanager/class/DMMClient.class.php');

	$dmmModule = new DMMModule($db);
	$dmmClient = new DMMClient($db);
	$allMods = $dmmModule->fetchAll();
	$checked = 0;

	foreach ($allMods as $mod) {
		if (!$mod->isCacheStale()) {
			continue;
		}

		// Public repos, hub-imported community modules and DoliStore rows have
		// no token — they must still be checked. Pass null and let DMMClient
		// pick the right path (anonymous GitHub call, GitLab, DoliStore API).
		$plainToken = null;
		if (!empty($mod->fk_dmm_token)) {
			$tokenObj = new DMMToken($db);
			if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
				$plainToken = $tokenObj->getDecryptedToken();
			}
		}

		$dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
		$checked++;
		if ($maxChecks > 0 && $checked >= $maxChecks) {
			break;
		}
	}

	return $checked;
}

/**
 * Return a JSON response for DMM ajax actions and stop execution.
 *
 * @param  array $payload Response payload
 * @return never
 */
function dmm_ajax_response($payload)
{
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	header('Content-Type: application/json; charset=utf-8');
	print json_encode($payload);
	exit;
}

/**
 * Check whether the current request expects a DMM ajax response.
 *
 * @return bool
 */
function dmm_is_ajax_request()
{
	return (GETPOSTINT('ajax') === 1 || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));
}

/**
 * HTML attributes that opt a link into the reusable DMM ajax loader.
 *
 * @param  string $label Loading label
 * @return string
 */
function dmm_ajax_attrs($label = '')
{
	$attrs = ' data-dmm-ajax="1"';
	if ($label !== '') {
		$attrs .= ' data-dmm-ajax-label="'.dol_escape_htmltag($label).'"';
	}
	return $attrs;
}

/**
 * Print the reusable DMM ajax loader assets once per page.
 *
 * Links with data-dmm-ajax="1" are fetched in the background. The page reloads
 * or redirects after completion so Dolibarr session messages remain the source
 * of truth for the final result.
 *
 * @return void
 */
function dmm_print_ajax_loader_assets()
{
	static $printed = false;
	if ($printed) {
		return;
	}
	$printed = true;

	global $langs;
	$loading = dol_escape_js($langs->trans('DMMLoadingExternal'));
	$wait = dol_escape_js($langs->trans('DMMPleaseWait'));
	$logFallback = dol_escape_js($langs->trans('DMMAjaxLogFallback'));
	$nonce = function_exists('getNonce') ? ' nonce="'.getNonce().'"' : '';

	print '<style>
.dmm-ajax-overlay{position:fixed;inset:0;z-index:100000;background:rgba(18,24,38,.42);display:none;align-items:center;justify-content:center;padding:24px}
.dmm-ajax-box{width:min(420px,calc(100vw - 48px));background:#fff;border:1px solid #d8dce3;border-radius:6px;box-shadow:0 18px 48px rgba(0,0,0,.22);padding:18px 20px}
.dmm-ajax-title{font-weight:600;margin-bottom:6px}
.dmm-ajax-detail{color:#5b6472;font-size:13px;margin-bottom:14px}
.dmm-ajax-bar{height:8px;background:#eef1f5;border-radius:999px;overflow:hidden}
.dmm-ajax-bar span{display:block;width:38%;height:100%;background:#2f7ed8;border-radius:999px;animation:dmmAjaxSlide 1.05s ease-in-out infinite}
.dmm-ajax-log{margin-top:14px;height:112px;overflow:auto;background:#f6f8fb;border:1px solid #e3e7ee;border-radius:4px;padding:8px 10px;color:#394150;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;font-size:12px;line-height:1.45;white-space:pre-wrap}
@keyframes dmmAjaxSlide{0%{transform:translateX(-110%)}100%{transform:translateX(280%)}}
</style>';
	print '<div class="dmm-ajax-overlay" id="dmmAjaxOverlay" aria-live="polite" aria-busy="true">';
	print '<div class="dmm-ajax-box">';
	print '<div class="dmm-ajax-title" id="dmmAjaxTitle">'.dol_escape_htmltag($langs->trans('DMMLoadingExternal')).'</div>';
	print '<div class="dmm-ajax-detail" id="dmmAjaxDetail">'.dol_escape_htmltag($langs->trans('DMMPleaseWait')).'</div>';
	print '<div class="dmm-ajax-bar"><span></span></div>';
	print '<div class="dmm-ajax-log" id="dmmAjaxLog"></div>';
	print '</div></div>';
	print '<script'.$nonce.'>
(function () {
	var overlay = document.getElementById("dmmAjaxOverlay");
	var title = document.getElementById("dmmAjaxTitle");
	var detail = document.getElementById("dmmAjaxDetail");
	var logBox = document.getElementById("dmmAjaxLog");
	if (!overlay || !title || !detail || !logBox || window.__dmmAjaxLoaderReady) return;
	window.__dmmAjaxLoaderReady = true;
	function now() {
		return new Date().toLocaleTimeString([], {hour: "2-digit", minute: "2-digit", second: "2-digit"});
	}
	function log(message) {
		// Only stick to the bottom if the user is already there; otherwise leave
		// their scroll position untouched so the log does not jump on each new line.
		var atBottom = (logBox.scrollHeight - logBox.scrollTop - logBox.clientHeight) <= 4;
		logBox.textContent += "[" + now() + "] " + message + "\n";
		if (atBottom) {
			logBox.scrollTop = logBox.scrollHeight;
		}
	}
	function show(label) {
		title.textContent = label || "'.$loading.'";
		detail.textContent = "'.$wait.'";
		logBox.textContent = "";
		overlay.style.display = "flex";
	}
	function fetchJson(url) {
		return fetch(url.toString(), {
			credentials: "same-origin",
			headers: {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"}
		}).then(function (response) {
			return response.json().catch(function () { return {success:false}; });
		});
	}
	function logResults(results) {
		if (!results || typeof results !== "object") return 0;
		var count = 0;
		Object.keys(results).forEach(function (moduleId) {
			var result = results[moduleId];
			var suffix = result && result.ok ? " - OK" : " - " + ((result && result.error) ? result.error : "KO");
			log(moduleId + suffix);
			count++;
		});
		return count;
	}
	function hide() {
		overlay.style.display = "none";
	}
	function runModuleCheckBatch(link) {
		var scope = link.getAttribute("data-dmm-scope") || "all";
		var listUrl = new URL(link.href, window.location.href);
		listUrl.searchParams.set("action", "checktargets");
		listUrl.searchParams.set("scope", scope);
		listUrl.searchParams.set("ajax", "1");
		fetchJson(listUrl).then(function (payload) {
			var targets = payload && Array.isArray(payload.targets) ? payload.targets : [];
			if (!targets.length) {
				log("No module to check");
				window.setTimeout(function () { window.location.reload(); }, 500);
				return;
			}
			var index = 0;
			var failed = 0;
			function next() {
				if (index >= targets.length) {
					detail.textContent = targets.length + " / " + targets.length;
					window.setTimeout(function () {
						var doneUrl = new URL(link.href, window.location.href);
						doneUrl.searchParams.set("action", "checkbatchdone");
						doneUrl.searchParams.set("checked", String(targets.length));
						doneUrl.searchParams.set("failed", String(failed));
						window.location.href = doneUrl.toString();
					}, 900);
					return;
				}
				var target = targets[index];
				detail.textContent = (index + 1) + " / " + targets.length + " - " + target.module_id;
				var checkUrl = new URL(target.url, window.location.href);
				checkUrl.searchParams.set("ajax", "1");
				fetchJson(checkUrl).then(function (checkPayload) {
					var logged = checkPayload ? logResults(checkPayload.results) : 0;
					if (!logged) {
						log(target.module_id + " - KO");
					}
					if (!checkPayload || checkPayload.success !== true) {
						failed++;
					}
					index++;
					next();
				}).catch(function () {
					log(target.module_id + " - '.$logFallback.'");
					failed++;
					index++;
					next();
				});
			}
			next();
		}).catch(function () {
			log("'.$logFallback.'");
			hide();
			window.location.href = link.href;
		});
	}
	document.addEventListener("click", function (event) {
		var link = event.target.closest ? event.target.closest("a[data-dmm-ajax=\"1\"]") : null;
		if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		show(link.getAttribute("data-dmm-ajax-label") || link.textContent.trim());
		if (link.getAttribute("data-dmm-batch") === "module-checks") {
			runModuleCheckBatch(link);
			return;
		}
		var url = new URL(link.href, window.location.href);
		url.searchParams.set("ajax", "1");
		fetchJson(url).then(function (payload) {
			var logged = payload ? logResults(payload.results) : 0;
			if (!logged && payload && Array.isArray(payload.logs)) {
				payload.logs.forEach(function (line) {
					log(line);
				});
			}
			window.setTimeout(function () {
				if (payload && payload.redirect) {
					window.location.href = payload.redirect;
				} else {
					window.location.reload();
				}
			}, logged ? 900 : 0);
		}).catch(function () {
			log("'.$logFallback.'");
			hide();
			window.location.href = link.href;
		});
	});
}());
</script>';
}

/**
 * Get hub list from settings. Handles both old format (array of strings)
 * and new format (array of objects with url + enabled).
 *
 * @return array Array of ['url' => string, 'enabled' => int]
 */
function dmm_get_hubs()
{
	$raw = json_decode(dmm_get_setting('hub_urls', '[]'), true) ?: array();
	$hubs = array();
	foreach ($raw as $entry) {
		if (is_string($entry)) {
			// Old format: plain URL string
			$hubs[] = array('url' => $entry, 'enabled' => 1);
		} elseif (is_array($entry) && !empty($entry['url'])) {
			$hubs[] = array('url' => $entry['url'], 'enabled' => (int) ($entry['enabled'] ?? 1));
		}
	}
	return $hubs;
}

/**
 * Canonical identity of a hub URL, so the same dmmhub.json referenced by different
 * URL forms is recognised as ONE hub. GitHub exposes the same file as:
 *   - https://raw.githubusercontent.com/OWNER/REPO/BRANCH/dmmhub.json
 *   - https://api.github.com/repos/OWNER/REPO/contents/dmmhub.json
 *   - https://github.com/OWNER/REPO/blob/BRANCH/dmmhub.json
 * All three resolve to "github:OWNER/REPO". Non-GitHub hubs fall back to their
 * URL with scheme and trailing slash normalised.
 *
 * @param  string $url Hub URL in any form
 * @return string      Canonical identity key (lowercased for GitHub owner/repo)
 */
function dmm_hub_identity($url)
{
	$url = trim((string) $url);
	$host = strtolower((string) parse_url($url, PHP_URL_HOST));
	$path = (string) parse_url($url, PHP_URL_PATH);

	$owner = $repo = null;
	if ($host === 'raw.githubusercontent.com') {
		// /OWNER/REPO/BRANCH/.../dmmhub.json
		if (preg_match('#^/([^/]+)/([^/]+)/#', $path, $m)) {
			list(, $owner, $repo) = $m;
		}
	} elseif ($host === 'api.github.com') {
		// /repos/OWNER/REPO/contents/dmmhub.json
		if (preg_match('#^/repos/([^/]+)/([^/]+)/#', $path, $m)) {
			list(, $owner, $repo) = $m;
		}
	} elseif ($host === 'github.com' || substr($host, -11) === '.github.com') {
		// /OWNER/REPO or /OWNER/REPO/blob/BRANCH/dmmhub.json
		if (preg_match('#^/([^/]+)/([^/]+)#', $path, $m)) {
			list(, $owner, $repo) = $m;
		}
	}

	if ($owner !== null && $repo !== null) {
		$repo = preg_replace('/\.git$/i', '', $repo);
		return 'github:'.strtolower($owner).'/'.strtolower($repo);
	}

	// Non-GitHub (or unrecognised): normalise scheme + trailing slash only.
	return rtrim(preg_replace('#^https?://#i', '', $url), '/');
}

/**
 * Save hub list to settings, deduplicated by canonical identity. When two entries
 * share an identity, the first wins but an enabled flag from any duplicate is kept
 * (so a discovered-disabled duplicate never silently disables an enabled hub).
 *
 * @param  array $hubs Array of ['url' => string, 'enabled' => int]
 * @return array       The deduplicated list actually saved
 */
function dmm_save_hubs($hubs)
{
	$byIdentity = array();
	foreach ($hubs as $hub) {
		if (empty($hub['url'])) {
			continue;
		}
		$id = dmm_hub_identity($hub['url']);
		if (!isset($byIdentity[$id])) {
			$byIdentity[$id] = array('url' => $hub['url'], 'enabled' => (int) ($hub['enabled'] ?? 1));
		} elseif (!empty($hub['enabled'])) {
			$byIdentity[$id]['enabled'] = 1;
		}
	}
	$deduped = array_values($byIdentity);
	dmm_set_setting('hub_urls', json_encode($deduped));
	return $deduped;
}

/**
 * Format a file size in bytes to human-readable (Ko, Mo, Go).
 *
 * @param  int    $bytes Size in bytes
 * @return string        Formatted size
 */
function dmm_format_size($bytes)
{
	if ($bytes >= 1073741824) {
		return round($bytes / 1073741824, 1).' Go';
	} elseif ($bytes >= 1048576) {
		return round($bytes / 1048576, 1).' Mo';
	} elseif ($bytes >= 1024) {
		return round($bytes / 1024, 1).' Ko';
	}
	return $bytes.' o';
}

/**
 * Run module migration (init) after install/update.
 * Calls the module descriptor's init() which runs SQL and re-registers menus/permissions.
 *
 * @param  string $module_id Module ID (directory name in /custom/)
 * @param  DoliDB $db        Database handler
 * @return bool              True on success
 */
function dmm_run_module_migration($module_id, $db)
{
	$customDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
	$coreModulesDir = $customDir.'/core/modules/';
	if (!is_dir($coreModulesDir)) {
		return false;
	}

	$files = glob($coreModulesDir.'mod*.class.php');
	if (empty($files)) {
		return false;
	}

	$descriptorFile = $files[0];
	$className = basename($descriptorFile, '.class.php');

	include_once $descriptorFile;
	if (!class_exists($className)) {
		return false;
	}

	$modInstance = new $className($db);
	$result = $modInstance->init();
	return ($result >= 0);
}

/**
 * Build the URL of a module's setup page from its descriptor's config_page_url.
 *
 * Dolibarr accepts several spellings: "setup.php@mymodule" (page@module, the
 * documented one), a bare "setup.php" (relative to the module's admin/ dir), or
 * an absolute URL. Returns '' when the module has no setup page or the
 * descriptor cannot be loaded, so callers can skip the link silently.
 *
 * @param  string $module_id Directory name under custom/
 * @return string            Absolute URL, or '' if none
 */
function dmm_module_setup_url($module_id)
{
	$files = glob(DOL_DOCUMENT_ROOT.'/custom/'.$module_id.'/core/modules/mod*.class.php');
	if (empty($files)) {
		return '';
	}
	$className = basename($files[0], '.class.php');
	include_once $files[0];
	if (!class_exists($className)) {
		return '';
	}
	global $db;
	try {
		$inst = new $className($db);
	} catch (Throwable $e) {
		return '';
	}
	$cfg = $inst->config_page_url ?? '';
	if (is_array($cfg)) {
		$cfg = reset($cfg);
	}
	$cfg = is_string($cfg) ? trim($cfg) : '';
	if ($cfg === '') {
		return '';
	}
	if (preg_match('#^https?://#i', $cfg)) {
		return $cfg;
	}
	if (strpos($cfg, '@') !== false) {
		list($page, $dir) = explode('@', $cfg, 2);
		return dol_buildpath('/'.$dir.'/admin/'.$page, 1);
	}
	return dol_buildpath('/'.$module_id.'/admin/'.$cfg, 1);
}

/**
 * Push a "go to module settings" toast after a successful install/update,
 * when the module declares a setup page. No-op otherwise.
 *
 * @param  string    $module_id Directory name under custom/
 * @param  Translate $langs     Language object
 * @return void
 */
function dmm_show_setup_link_toast($module_id, $langs)
{
	$url = dmm_module_setup_url($module_id);
	if ($url === '') {
		return;
	}
	setEventMessages('<a href="'.dol_escape_htmltag($url).'">'.$langs->trans('DMMGoToModuleSetup').'</a>', null, 'mesgs');
}

/**
 * Show discovery report as toast messages.
 *
 * @param  array     $discovery Result from DMMClient::discoverModules()
 * @param  Translate $langs     Language object
 * @return void
 */
function dmm_show_discovery_report($discovery, $langs)
{
	$scan = $discovery['scan'] ?? array();
	$visibleCount = count($scan['repos_visible'] ?? array());
	$dmmRepos = $scan['repos_dmm'] ?? array();
	$otherRepos = $scan['repos_other'] ?? array();

	// If the token exposes zero repositories, discovery cannot find anything — tell
	// the user why (this is the usual "I added a token but nothing happened" case:
	// invalid/expired token, missing repo scope, or a fine-grained token with no
	// repositories selected) instead of a bare "0 repos" that reads as a no-op.
	if ($visibleCount === 0) {
		setEventMessages($langs->trans('DMMNoReposVisible'), null, 'warnings');
		if (!empty($discovery['errors'])) {
			setEventMessages(implode(', ', $discovery['errors']), null, 'warnings');
		}
		return;
	}

	$summary = $langs->trans('DMMReposVisible', $visibleCount);
	if (!empty($dmmRepos)) {
		$summary .= ' | '.$langs->trans('DMMReposDMM', count($dmmRepos), implode(', ', $dmmRepos));
	}
	setEventMessages($summary, null, 'mesgs');

	$hubRepos = $scan['repos_hub'] ?? array();
	if (!empty($hubRepos)) {
		setEventMessages($langs->trans('DMMHubsFound', count($hubRepos), implode(', ', $hubRepos)), null, 'mesgs');
	}
	if (!empty($otherRepos)) {
		setEventMessages($langs->trans('DMMReposNoDMM', count($otherRepos), implode(', ', $otherRepos)), null, 'mesgs');
	}
	if (!empty($discovery['hubs_found'])) {
		setEventMessages($langs->trans('DMMHubsAutoRegistered', count($discovery['hubs_found']), implode(', ', $discovery['hubs_found'])), null, 'mesgs');
	}
	if ($discovery['discovered'] > 0) {
		setEventMessages($langs->trans('DMMNewModulesRegistered', $discovery['discovered']), null, 'mesgs');
	}
	if ($discovery['skipped'] > 0) {
		setEventMessages($langs->trans('DMMModulesAlreadyRegistered', $discovery['skipped']), null, 'mesgs');
	}
	if (!empty($discovery['errors'])) {
		setEventMessages(implode(', ', $discovery['errors']), null, 'warnings');
	}
}

/**
 * Load the DoliStore purchase list for the scan table, reusing the same cache file
 * the purchases tab writes so a scan right after a visit there costs no network.
 * Never fatal: missing credentials or a dead session simply yield an empty list and
 * the DoliStore-purchase column degrades to "not available".
 *
 * @param  DoliDB $db Database handle
 * @return array<int,array> Products from DMMDolistoreSession::fetchPurchases()
 */
function dmm_scan_load_purchases($db)
{
	global $conf;

	dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');

	$baseTemp = isset($conf->dolimodulemanager->dir_temp)
		? $conf->dolimodulemanager->dir_temp
		: DOL_DATA_ROOT.'/dolimodulemanager/temp';
	$cacheFile = $baseTemp.'/dolistore_purchases.json';
	$cacheTtl = 3600;

	if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
		$cached = @json_decode(file_get_contents($cacheFile), true);
		if (is_array($cached) && isset($cached['products'])) {
			return $cached['products'];
		}
	}

	$ses = new DMMDolistoreSession($db);
	if (!$ses->hasCredentials()) {
		return array();
	}
	$result = $ses->fetchPurchases();
	if (empty($result['ok'])) {
		return array();
	}
	if (!is_dir($baseTemp)) {
		@dol_mkdir($baseTemp);
	}
	@file_put_contents($cacheFile, json_encode(array(
		'ok' => true,
		'products' => $result['products'],
		'fetched_at' => time(),
	)));
	return $result['products'];
}

/**
 * DoliStore search URL for a module directory name.
 *
 * Two things matter here and both were wrong before. The endpoint must be the
 * marketplace search (`search_query`): the shorter `?controller=search&s=` form
 * answers 200 but ranks by something else entirely — searching "changetiers"
 * that way returned DolicraftFlotte and DoliTheme, and never the module itself.
 *
 * The keyword matters as much as the endpoint. Underscores, dashes and camelCase
 * are split back into words, since "dolibarr module lead" searches where
 * "dolibarr_module_lead" does not. All-lowercase glued names ("changetiers")
 * cannot be split without a dictionary and will often return nothing — the
 * descriptor is no help either (it holds the same glued name), so the link is a
 * starting point for the user, not a guaranteed hit.
 *
 * @param  string $moduleId Module directory name
 * @return string           Absolute search URL
 */
function dmm_dolistore_search_url($moduleId)
{
	$words = preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $moduleId);
	$words = preg_replace('/[_-]+/', ' ', $words);
	$words = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $words);
	$words = trim(preg_replace('/\s+/', ' ', $words));

	return 'https://www.dolistore.com/index.php?'.http_build_query(array(
		'controller' => 'search',
		'orderby' => 'position',
		'orderway' => 'desc',
		'website' => 'marketplace',
		'search_query' => $words,
		'submit_search' => '',
	));
}

/**
 * Extract a DoliStore product id from whatever the user pasted.
 *
 * Accepts a bare id, a product.php?id=N URL, or a friendly /N-slug URL. The same
 * three forms were being re-parsed in every place that takes a DoliStore link.
 *
 * @param  string $raw Raw user input
 * @return int         Product id, or 0 when nothing usable was found
 */
function dmm_parse_dolistore_id($raw)
{
	$raw = trim((string) $raw);
	if ($raw === '') {
		return 0;
	}
	if (preg_match('/^\d+$/', $raw)) {
		return (int) $raw;
	}
	if (preg_match('/[?&]id=(\d+)/', $raw, $m)) {
		return (int) $m[1];
	}
	if (preg_match('#/(\d+)-#', $raw, $m)) {
		return (int) $m[1];
	}
	return 0;
}

/**
 * Authenticated download URL for a DoliStore product the account owns.
 *
 * The registry records WHICH storefront a module came from but not HOW to fetch
 * its bytes, so a paid product was indistinguishable from a free one and every
 * install routed to the anonymous endpoint — which answers "paiedProduct" and
 * fails. Callers use this to pick the pipeline: a non-null URL means the account
 * owns the product and the authenticated download applies.
 *
 * Reads the purchase cache only. dmm_scan_load_purchases() already degrades to an
 * empty list when credentials are missing or the scrape fails, so a free product,
 * an unowned one and an unconfigured account all return null alike.
 *
 * @param  DoliDB $db          Database handle
 * @param  int    $dolistoreId DoliStore product id
 * @return string|null         wrapper.php URL, or null
 */
function dmm_dolistore_wrapper_url($db, $dolistoreId)
{
	$dolistoreId = (int) $dolistoreId;
	if ($dolistoreId <= 0) {
		return null;
	}
	foreach (dmm_scan_load_purchases($db) as $p) {
		if ((int) ($p['id'] ?? 0) === $dolistoreId && !empty($p['zip_url'])) {
			return (string) $p['zip_url'];
		}
	}
	return null;
}

/**
 * Show hub import report as toast messages.
 *
 * @param  array $report Result from DMMClient::importFromHub()
 * @return void
 */
function dmm_show_hub_report($report)
{
	$hubName = $report['hub_name'] ?: 'Hub';
	setEventMessages('Hub: '.$hubName, null, 'mesgs');

	global $langs;
	$summary = $report['total'].' modules | '.$report['public'].' public, '.$report['private'].' private';
	setEventMessages($summary, null, 'mesgs');

	if ($report['registered'] > 0) {
		setEventMessages($langs->trans('DMMNewModulesRegistered', $report['registered']), null, 'mesgs');
	}
	if ($report['matched'] > 0) {
		setEventMessages($langs->trans('DMMModulesMatchedToken', $report['matched']), null, 'mesgs');
	}
	if ($report['needs_token'] > 0) {
		setEventMessages($langs->trans('DMMModulesNeedToken', $report['needs_token']), null, 'warnings');
	}
	if ($report['skipped'] > 0) {
		setEventMessages($langs->trans('DMMModulesAlreadyRegistered', $report['skipped']), null, 'mesgs');
	}
	if (!empty($report['errors'])) {
		setEventMessages(implode(', ', $report['errors']), null, 'errors');
	}
}

/**
 * Render a picto tooltip (?) next to a label, reusing Dolibarr's Form::textwithpicto.
 * Central helper so every DMM page shows the same discreet, clickable help marker.
 *
 * @param  string $label     Already-translated label text
 * @param  string $helpKey   Lang key holding the tooltip text (translated here)
 * @param  string $urlanchor Optional anchor on the Dashboard help section (e.g. 'channels')
 * @return string            HTML for label + picto
 */
function dmm_label_help($label, $helpKey, $urlanchor = '')
{
	global $langs, $form;
	if (!is_object($form)) {
		include_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		$form = new Form($GLOBALS['db']);
	}
	$text = $langs->trans($helpKey);
	if ($urlanchor !== '') {
		// Point deeper explanations at the Dashboard's help section anchor.
		$text .= '<br><em>'.$langs->trans('DMMSeeHelpSection').'</em>';
	}
	return $form->textwithpicto($label, $text, 1, 'help');
}

/**
 * Render the collapsible "Help & troubleshooting" section shown at the bottom of
 * the Dashboard. Pure HTML (<details>), no JS dependency. Explains the domain
 * concepts a first-time user needs and the most common failure fixes.
 *
 * @return string HTML block
 */
function dmm_help_section()
{
	global $langs;

	// One place to declare the concept + troubleshooting entries. Each is a pair
	// of lang keys (term/definition) so the whole section is translatable.
	$concepts = array(
		'sources'   => 'DMMHelpConceptSources',
		'channels'  => 'DMMHelpConceptChannels',
		'tokens'    => 'DMMHelpConceptTokens',
		'backups'   => 'DMMHelpConceptBackups',
		'gitlab'    => 'DMMHelpConceptGitlab',
	);
	$troubles = array(
		'perms'     => 'DMMHelpTroublePerms',
		'needtoken' => 'DMMHelpTroubleNeedToken',
		'ratelimit' => 'DMMHelpTroubleRateLimit',
	);

	$out = '<br>';
	$out .= '<details class="dmm-help-section">';
	$out .= '<summary class="paddingtop paddingbottom" style="cursor:pointer;font-weight:bold;">';
	$out .= img_picto('', 'fa-question-circle', 'class="paddingright"').$langs->trans('DMMHelpAndTroubleshooting');
	$out .= '</summary>';

	$out .= '<div class="div-table-responsive-no-min opacitymedium" style="padding:10px 4px;">';

	// Note: $langs->trans() already returns HTML-safe text (entities encoded), so we
	// must NOT re-escape it here — doing so double-encodes '&' into '&amp;amp;'.

	// Quick start
	$out .= '<p><strong>'.$langs->trans('DMMHelpQuickStartTitle').'</strong><br>';
	$out .= $langs->trans('DMMHelpQuickStartBody').'</p>';

	// Concepts
	$out .= '<p class="paddingtop"><strong>'.$langs->trans('DMMHelpConceptsTitle').'</strong></p>';
	$out .= '<ul>';
	foreach ($concepts as $anchor => $key) {
		// Each concept key holds "Term|Definition" so we can bold the term.
		$parts = explode('|', $langs->trans($key), 2);
		$term = $parts[0];
		$def = isset($parts[1]) ? $parts[1] : '';
		$out .= '<li id="dmmhelp-'.$anchor.'"><strong>'.$term.'</strong> — '.$def.'</li>';
	}
	$out .= '</ul>';

	// Troubleshooting
	$out .= '<p class="paddingtop"><strong>'.$langs->trans('DMMHelpTroubleTitle').'</strong></p>';
	$out .= '<ul>';
	foreach ($troubles as $anchor => $key) {
		$parts = explode('|', $langs->trans($key), 2);
		$term = $parts[0];
		$def = isset($parts[1]) ? $parts[1] : '';
		$out .= '<li id="dmmhelp-'.$anchor.'"><strong>'.$term.'</strong> — '.$def.'</li>';
	}
	$out .= '</ul>';

	// Link to the preflight diagnostics (the tool that actually checks perms/PHP/GitHub).
	$preflightUrl = dol_buildpath('/dolimodulemanager/dmm_preflight_web.php', 1);
	$out .= '<p class="paddingtop">'.img_picto('', 'fa-stethoscope', 'class="paddingright"');
	$out .= '<a href="'.$preflightUrl.'">'.$langs->trans('DMMHelpRunPreflight').'</a></p>';

	$out .= '</div></details>';

	return $out;
}
