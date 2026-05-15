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

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/marketplace.php', 1);
	$head[$h][1] = $langs->trans('DMMMarketplace');
	$head[$h][2] = 'marketplace';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/purchases.php', 1);
	$head[$h][1] = $langs->trans('DMMPurchases');
	$head[$h][2] = 'purchases';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('DMMSettingsTab');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager', 'remove');

	return $head;
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
 * @return int Number of modules checked (0 if nothing to do)
 */
function dmm_auto_check_updates()
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
	$logPrepare = dol_escape_js($langs->trans('DMMAjaxLogPrepare'));
	$logConnect = dol_escape_js($langs->trans('DMMAjaxLogConnect'));
	$logProcess = dol_escape_js($langs->trans('DMMAjaxLogProcess'));
	$logReload = dol_escape_js($langs->trans('DMMAjaxLogReload'));
	$logFallback = dol_escape_js($langs->trans('DMMAjaxLogFallback'));
	$nonce = function_exists('getNonce') ? ' nonce="'.getNonce().'"' : '';

	print '<style>
.dmm-ajax-overlay{position:fixed;inset:0;z-index:100000;background:rgba(18,24,38,.42);display:none;align-items:center;justify-content:center;padding:24px}
.dmm-ajax-box{width:min(420px,calc(100vw - 48px));background:#fff;border:1px solid #d8dce3;border-radius:6px;box-shadow:0 18px 48px rgba(0,0,0,.22);padding:18px 20px}
.dmm-ajax-title{font-weight:600;margin-bottom:6px}
.dmm-ajax-detail{color:#5b6472;font-size:13px;margin-bottom:14px}
.dmm-ajax-bar{height:8px;background:#eef1f5;border-radius:999px;overflow:hidden}
.dmm-ajax-bar span{display:block;width:38%;height:100%;background:#2f7ed8;border-radius:999px;animation:dmmAjaxSlide 1.05s ease-in-out infinite}
.dmm-ajax-log{margin-top:14px;max-height:112px;overflow:auto;background:#f6f8fb;border:1px solid #e3e7ee;border-radius:4px;padding:8px 10px;color:#394150;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;font-size:12px;line-height:1.45;white-space:pre-wrap}
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
	var timer = null;
	function now() {
		return new Date().toLocaleTimeString([], {hour: "2-digit", minute: "2-digit", second: "2-digit"});
	}
	function log(message) {
		logBox.textContent += "[" + now() + "] " + message + "\n";
		logBox.scrollTop = logBox.scrollHeight;
	}
	function show(label) {
		title.textContent = label || "'.$loading.'";
		detail.textContent = "'.$wait.'";
		logBox.textContent = "";
		log("'.$logPrepare.'");
		overlay.style.display = "flex";
		timer = window.setTimeout(function () {
			log("'.$logProcess.'");
		}, 1200);
	}
	function hide() {
		if (timer) {
			window.clearTimeout(timer);
			timer = null;
		}
		overlay.style.display = "none";
	}
	document.addEventListener("click", function (event) {
		var link = event.target.closest ? event.target.closest("a[data-dmm-ajax=\"1\"]") : null;
		if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		event.preventDefault();
		show(link.getAttribute("data-dmm-ajax-label") || link.textContent.trim());
		var url = new URL(link.href, window.location.href);
		url.searchParams.set("ajax", "1");
		log("'.$logConnect.'");
		fetch(url.toString(), {
			credentials: "same-origin",
			headers: {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"}
		}).then(function (response) {
			return response.json().catch(function () { return {success:false, redirect: link.href}; });
		}).then(function (payload) {
			log("'.$logReload.'");
			if (payload && payload.redirect) {
				window.location.href = payload.redirect;
			} else {
				window.location.reload();
			}
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
 * Save hub list to settings.
 *
 * @param  array $hubs Array of ['url' => string, 'enabled' => int]
 * @return void
 */
function dmm_save_hubs($hubs)
{
	dmm_set_setting('hub_urls', json_encode(array_values($hubs)));
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
