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

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/advanced.php', 1);
	$head[$h][1] = $langs->trans('DMMAdvancedTab');
	$head[$h][2] = 'advanced';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/sources.php', 1);
	$head[$h][1] = $langs->trans('DMMSourcesTab');
	$head[$h][2] = 'sources';
	$h++;

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
 * Load the public DoliStore catalog for the manual picker. The order history only
 * lists paid modules, so free modules installed from DoliStore can only be picked
 * from the full catalog. Failures degrade to an empty list.
 *
 * @return array<int,array> Raw products (id, label, ...)
 */
function dmm_scan_load_catalog()
{
	dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
	$ds = new DMMDolistoreClient();
	$products = $ds->getAllProducts();
	return is_array($products) ? $products : array();
}

/**
 * Render the scan selection table: one row per detected module, one column per
 * source kind. The source the scan preferred is preselected (GitHub, then a
 * DoliStore purchase, then the public catalog) but every available source stays
 * clickable, and modules with no source at all can be pointed at a repo by hand or
 * bound to one of the account's purchases.
 *
 * @param  array     $scan      Result from DMMClient::scanLocalCandidates()
 * @param  array     $purchases Products from fetchPurchases(), for the manual picker
 * @param  Translate $langs     Language object
 * @return void
 */
function dmm_show_scan_table($scan, $purchases, $langs)
{
	$candidates = $scan['candidates'] ?? array();
	$matched = $scan['matched_existing'] ?? array();
	$skippedCore = $scan['skipped_core'] ?? array();
	$errors = $scan['errors'] ?? array();

	if (!empty($errors)) {
		setEventMessages(implode(', ', $errors), null, 'errors');
	}

	print '<br>';
	$summary = array();
	if (!empty($matched)) {
		$summary[] = $langs->trans('DMMScanLocalMatchedExisting', count($matched));
	}
	if (!empty($skippedCore)) {
		$summary[] = $langs->trans('DMMScanLocalSkippedCore', count($skippedCore));
	}
	if (!empty($summary)) {
		print '<div class="opacitymedium small">'.implode(' &nbsp;·&nbsp; ', $summary).'</div>';
	}

	if (empty($candidates)) {
		print '<div class="opacitymedium">'.$langs->trans('DMMScanLocalNoneFound').'</div>';
		return;
	}

	// Options for the manual DoliStore picker. The order history only ever lists
	// PAID modules, so a free module downloaded from DoliStore would be unpickable
	// if we stopped there: the picker offers the whole public catalog, with the
	// account's purchases pulled to the top as their own group.
	$boundIds = array();
	foreach ($candidates as $c) {
		if (isset($c['sources']['dolistore_purchase']['dolistore_id'])) {
			$boundIds[(int) $c['sources']['dolistore_purchase']['dolistore_id']] = true;
		}
	}
	$purchaseOptions = array();
	$purchaseIds = array();
	foreach ((array) $purchases as $p) {
		if (empty($p['id']) || isset($boundIds[(int) $p['id']])) {
			continue;
		}
		$purchaseIds[(int) $p['id']] = true;
		$purchaseOptions[] = array('id' => (int) $p['id'], 'label' => (string) $p['name']);
	}
	$catalogOptions = array();
	foreach (dmm_scan_load_catalog() as $p) {
		$pid = (int) ($p['id'] ?? 0);
		if ($pid <= 0 || isset($purchaseIds[$pid]) || isset($boundIds[$pid])) {
			continue;
		}
		$catalogOptions[] = array('id' => $pid, 'label' => (string) ($p['label'] ?? ('#'.$pid)));
	}
	usort($catalogOptions, function ($a, $b) {
		return strcasecmp($a['label'], $b['label']);
	});
	$hasPicker = !empty($purchaseOptions) || !empty($catalogOptions);
	$pickerIds = array();

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="registerscan">';

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Module').'</td>';
	print '<td>'.$langs->trans('Version').'</td>';
	print '<td>'.$langs->trans('DMMScanSourceGithub').'</td>';
	print '<td>'.$langs->trans('DMMScanSourceDolistore').'</td>';
	print '<td class="center">'.$langs->trans('DMMScanIgnore').'</td>';
	print '</tr>';

	foreach ($candidates as $cand) {
		$mid = $cand['module_id'];
		$esc = dol_escape_htmltag($mid);
		$sources = $cand['sources'];
		$preselect = $cand['preselect'];

		print '<tr class="oddeven">';
		print '<td class="nowraponall"><strong>'.$esc.'</strong></td>';
		print '<td>'.dol_escape_htmltag($cand['version'] ?: '-').'</td>';

		// --- GitHub column: detected repo, else a free-text field ---
		print '<td>';
		if (isset($sources['github'])) {
			$s = $sources['github'];
			$originLabel = $s['origin'] === 'hub' ? $langs->trans('DMMScanFromHub') : $langs->trans('DMMScanFromManifest');
			print '<label><input type="radio" name="choice['.$esc.']" value="github"'.($preselect === 'github' ? ' checked' : '').'> ';
			print dol_escape_htmltag($s['github_repo']);
			print ' <span class="opacitymedium small">('.$originLabel.')</span></label>';
			print '<input type="hidden" name="sources['.$esc.'_github]" value="'.dol_escape_htmltag(json_encode($s)).'">';
		} else {
			print '<label class="nowraponall"><input type="radio" name="choice['.$esc.']" value="manual_github"> ';
			print '<input type="text" name="manual_repo['.$esc.']" class="minwidth200" placeholder="owner/repo">';
			print '</label>';
		}
		print '</td>';

		// --- DoliStore column: purchase first, then the public catalog match ---
		print '<td>';
		$printed = false;
		if (isset($sources['dolistore_purchase'])) {
			$s = $sources['dolistore_purchase'];
			print '<label><input type="radio" name="choice['.$esc.']" value="dolistore_purchase"'.($preselect === 'dolistore_purchase' ? ' checked' : '').'> ';
			print dol_escape_htmltag($s['name'] ?: ('#'.$s['dolistore_id']));
			print ' <span class="badge badge-status4 badge-status">'.$langs->trans('DMMScanPurchased').'</span></label>';
			print '<input type="hidden" name="sources['.$esc.'_dolistore_purchase]" value="'.dol_escape_htmltag(json_encode($s)).'">';
			$printed = true;
		}
		if (isset($sources['dolistore_public'])) {
			$s = $sources['dolistore_public'];
			print ($printed ? '<br>' : '');
			print '<label><input type="radio" name="choice['.$esc.']" value="dolistore_public"'.($preselect === 'dolistore_public' ? ' checked' : '').'> ';
			print dol_escape_htmltag($s['name'] ?: ('#'.$s['dolistore_id']));
			print ' <span class="opacitymedium small">('.$langs->trans('DMMScanFromCatalog').')</span></label>';
			print '<input type="hidden" name="sources['.$esc.'_dolistore_public]" value="'.dol_escape_htmltag(json_encode($s)).'">';
			$printed = true;
		}
		if (!$printed) {
			if ($hasPicker) {
				// Only the (short) purchase list is inlined per row. The ~1700-entry
				// catalog is emitted once as JSON below and injected into whichever
				// picker the user actually opens — inlining it in every row would push
				// the page past a megabyte.
				$pickerId = 'dmmpick_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $mid);
				$pickerIds[] = $pickerId;
				print '<label class="nowraponall"><input type="radio" name="choice['.$esc.']" value="manual_purchase"> ';
				print '<select id="'.$pickerId.'" name="manual_purchase['.$esc.']" class="minwidth200 dmm-scan-pick">';
				print '<option value="0">'.$langs->trans('DMMScanPickProduct').'</option>';
				if (!empty($purchaseOptions)) {
					print '<optgroup label="'.dol_escape_htmltag($langs->trans('DMMScanGroupPurchases')).'">';
					foreach ($purchaseOptions as $o) {
						print '<option value="'.$o['id'].'">'.dol_escape_htmltag($o['label']).' (#'.$o['id'].')</option>';
					}
					print '</optgroup>';
				}
				print '</select></label>';
			} else {
				print '<span class="opacitymedium small">'.$langs->trans('DMMScanNoPurchaseAvailable').'</span>';
			}
		}
		print '</td>';

		// --- Ignore ---
		print '<td class="center"><input type="radio" name="choice['.$esc.']" value="ignore"'.($preselect === null ? ' checked' : '').'></td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';

	print '<div class="center"><br><input type="submit" class="button" value="'.$langs->trans('DMMScanRegisterSelection').'"></div>';
	print '</form>';

	// Emit the catalog once, then fill each picker from it on first use.
	if (!empty($pickerIds)) {
		print '<script>';
		print 'var dmmScanCatalog = '.json_encode($catalogOptions).';';
		print 'var dmmScanCatalogLabel = '.json_encode($langs->trans('DMMScanGroupCatalog')).';';
		print '
function dmmFillCatalog(sel) {
	var $sel = $(sel);
	if ($sel.data("dmmFilled") || !dmmScanCatalog.length) { return; }
	$sel.data("dmmFilled", true);
	var parts = [];
	for (var i = 0; i < dmmScanCatalog.length; i++) {
		parts.push(\'<option value="\' + dmmScanCatalog[i].id + \'"></option>\');
	}
	var $group = $(\'<optgroup>\').attr("label", dmmScanCatalogLabel).html(parts.join(""));
	// Set the text through .text() so a product label can never inject markup.
	$group.children("option").each(function (i) {
		$(this).text(dmmScanCatalog[i].label + " (#" + dmmScanCatalog[i].id + ")");
	});
	$sel.append($group);
}
$(document).ready(function() {
	// select2 fires this before opening its dropdown; fall back to focus/click for
	// a plain <select> when select2 is unavailable.
	$(".dmm-scan-pick").on("select2:opening focus mousedown", function() {
		dmmFillCatalog(this);
	});
	// Tick the matching radio as soon as a product is picked or a repo is typed,
	// so choosing a source stays a single gesture.
	$(".dmm-scan-pick").on("change", function() {
		if ($(this).val() > 0) {
			$(this).closest("label").find("input[type=radio]").prop("checked", true);
		}
	});
	$("input[name^=manual_repo]").on("input", function() {
		if ($(this).val() !== "") {
			$(this).closest("label").find("input[type=radio]").prop("checked", true);
		}
	});
});
</script>';
		// select2 gives the picker its search box, which a 1700-entry list needs.
		if (function_exists('ajax_combobox')) {
			foreach ($pickerIds as $pid) {
				print ajax_combobox($pid);
			}
		}
	}
}

/**
 * Show the local module scan report as toast messages.
 *
 * @param  array     $report Result from DMMClient::scanLocalModules()
 * @param  Translate $langs  Language object
 * @return void
 */
function dmm_show_localscan_report($report, $langs)
{
	$registered = $report['registered'] ?? array();
	$matched = $report['matched_existing'] ?? array();
	$unmatched = $report['unmatched'] ?? array();
	$skippedCore = $report['skipped_core'] ?? array();
	$errors = $report['errors'] ?? array();

	if (!empty($registered)) {
		setEventMessages($langs->trans('DMMScanLocalRegistered', count($registered), implode(', ', $registered)), null, 'mesgs');
	}
	if (!empty($matched)) {
		setEventMessages($langs->trans('DMMScanLocalMatchedExisting', count($matched)), null, 'mesgs');
	}
	if (!empty($unmatched)) {
		$ids = array();
		foreach ($unmatched as $u) {
			$ids[] = $u['module_id'];
		}
		setEventMessages($langs->trans('DMMScanLocalUnmatched', count($unmatched), implode(', ', $ids)), null, 'warnings');
	}
	if (!empty($skippedCore)) {
		setEventMessages($langs->trans('DMMScanLocalSkippedCore', count($skippedCore)), null, 'mesgs');
	}
	if (empty($registered) && empty($unmatched) && empty($matched)) {
		setEventMessages($langs->trans('DMMScanLocalNoneFound'), null, 'mesgs');
	}
	if (!empty($errors)) {
		setEventMessages(implode(', ', $errors), null, 'errors');
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
