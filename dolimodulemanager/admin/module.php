<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/module.php
 * \ingroup dolimodulemanager
 * \brief   Single module detail page
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

dmm_require_right('read');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$isAjax = dmm_is_ajax_request();

$form = new Form($db);
$dmmClient = new DMMClient($db);

// Load module
$mod = new DMMModule($db);
if ($id > 0) {
	$mod->fetch($id);
} else {
	header('Location: '.dol_buildpath('/dolimodulemanager/admin/index.php', 1));
	exit;
}

/*
 * Actions
 */

// Check for updates
if ($action == 'checkupdate') {
	// Public repos and DoliStore modules have no token. Don't try to fetch one
	// — DMMClient::checkUpdate handles the null path (or short-circuits to the
	// DoliStore catalog API for source='dolistore').
	$plainToken = null;
	if (!empty($mod->fk_dmm_token)) {
		$tokenObj = new DMMToken($db);
		if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
			$plainToken = $tokenObj->getDecryptedToken();
		}
	}

	$result = $dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
	if ($result === null) {
		setEventMessages($dmmClient->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('DMMCheckComplete'), null, 'mesgs');
	}
	// Reload module data
	$mod->fetch($id);
	if ($isAjax) {
		dmm_ajax_response(array(
			'success' => ($result !== null),
			'redirect' => $_SERVER['PHP_SELF'].'?id='.$id,
			'results' => array(
				$mod->module_id => array(
					'ok' => ($result !== null),
					'error' => ($result === null ? $dmmClient->error : ''),
				),
			),
		));
	}
}

// AJAX: list the branches available on this module's repo, so the channel selector
// can be populated on demand (no API call on every page render — see plan).
if ($action == 'listbranches' && dmm_user_can('write') && dmm_is_dev_mode()) {
	$branches = array();
	$error = '';
	if (($mod->source ?? '') === 'dolistore' || empty($mod->github_repo) || strpos($mod->github_repo, '/') === false) {
		$error = $langs->trans('DMMNoBranchesFound');
	} else {
		$parsedRepo = $dmmClient->parseRepoSpec($mod->github_repo);
		list($owner, $repoName) = explode('/', $parsedRepo['repo'], 2);
		$gitHost = !empty($mod->git_host) ? $mod->git_host : 'github';
		$plainToken = null;
		if ($gitHost === 'github' && !empty($mod->fk_dmm_token)) {
			$tokenObj = new DMMToken($db);
			if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
				$plainToken = $tokenObj->getDecryptedToken();
			}
		}
		$list = $dmmClient->listBranches($owner, $repoName, $plainToken, $gitHost, $mod->git_base_url);
		if ($list === null) {
			$error = $dmmClient->error ?: $langs->trans('DMMNoBranchesFound');
		} else {
			foreach ($list as $b) {
				// Only flag a branch as current when the module actually follows it
				// (dev channel). branch_dev may hold a leftover/manifest value while
				// on stable — pre-selecting it would silently desync the <select>
				// from the real channel and swallow the onchange submit.
				$branches[] = array('name' => $b['name'], 'current' => ($mod->channel === 'dev' && $b['name'] === $mod->branch_dev));
			}
		}
	}
	if ($isAjax) {
		dmm_ajax_response(array('success' => empty($error), 'branches' => $branches, 'error' => $error));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

// Switch update channel for this module (only when developer mode is on). The value
// is either 'stable' (follow releases) or a branch name to follow via HEAD-SHA tracking.
if ($action == 'setchannel' && dmm_user_can('write') && dmm_is_dev_mode()) {
	$newChannel = GETPOST('channel', 'alphanohtml');
	$switched = false;
	if ($newChannel === 'stable') {
		$mod->channel = 'stable';
		$mod->invalidateCache();
		$mod->update($user);
		$switched = true;
		setEventMessages($langs->trans('DMMChannelSwitched'), null, 'mesgs');
	} elseif ($newChannel !== '') {
		// Treat the value as a branch name. Validate it against the repo's actual
		// branches before storing — a non-existent ref would silently break install.
		$valid = false;
		if (!empty($mod->github_repo) && strpos($mod->github_repo, '/') !== false && ($mod->source ?? '') !== 'dolistore') {
			$parsedRepo = $dmmClient->parseRepoSpec($mod->github_repo);
			list($owner, $repoName) = explode('/', $parsedRepo['repo'], 2);
			$gitHost = !empty($mod->git_host) ? $mod->git_host : 'github';
			$plainToken = null;
			if ($gitHost === 'github' && !empty($mod->fk_dmm_token)) {
				$tokenObj = new DMMToken($db);
				if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
					$plainToken = $tokenObj->getDecryptedToken();
				}
			}
			$list = $dmmClient->listBranches($owner, $repoName, $plainToken, $gitHost, $mod->git_base_url);
			if (is_array($list)) {
				foreach ($list as $b) {
					if ($b['name'] === $newChannel) {
						$valid = true;
						break;
					}
				}
			}
		}
		if ($valid) {
			$mod->channel = 'dev';
			$mod->branch_dev = $newChannel;
			$mod->invalidateCache();
			$mod->update($user);
			$switched = true;
			setEventMessages($langs->trans('DMMChannelSwitched'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('DMMInvalidBranch', $newChannel), null, 'errors');
		}
	}
	if ($switched) {
		// invalidateCache() just wiped cache_latest_compatible, which gates the
		// Install/Update button — refresh it now so the button reflects the new
		// channel right after redirect instead of requiring a manual "Check now".
		$plainToken = null;
		if (!empty($mod->fk_dmm_token)) {
			$tokenObj = new DMMToken($db);
			if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
				$plainToken = $tokenObj->getDecryptedToken();
			}
		}
		$dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

// Install or update
if ($action == 'confirm_install' && dmm_user_can('write')) {
	$tag = GETPOST('tag', 'alphanohtml');
	$activeChannel = dmm_module_tracks_branch($mod) ? 'dev' : 'stable';
	if (empty($tag)) {
		if ($activeChannel === 'dev') {
			$tag = $mod->branch_dev; // GitHub /tarball/{branch}
		} elseif (!empty($mod->cache_latest_compatible)) {
			$tag = 'v'.$mod->cache_latest_compatible;
		}
	}

	// DoliStore-sourced modules are installed via a different pipeline
	// (ZIP download from www.dolistore.com instead of a Git tarball). Plug
	// into the same post-install flow the GitHub path uses below: success
	// message + auto-migrate (or popup) + module row reload + redirect.
	if (($mod->source ?? '') === 'dolistore' && !empty($mod->dolistore_id)) {
		$wasInstalled = !empty($mod->installed);
		$previousVersion = $mod->installed_version;
		// Route on capability, not on source: a paid product must go through the
		// authenticated wrapper.php download. Owning it is what decides, and the only
		// evidence of ownership is the order history, so ask for its wrapper URL.
		// Null means free (or not owned) — the anonymous endpoint handles that.
		$wrapperUrl = dmm_dolistore_wrapper_url($db, (int) $mod->dolistore_id);
		$result = ($wrapperUrl !== null)
			? $dmmClient->installFromDolistorePurchase($mod->module_id, (int) $mod->dolistore_id, $wrapperUrl)
			: $dmmClient->installFromDolistoreZip($mod->module_id, (int) $mod->dolistore_id);
		if (!empty($result['success'])) {
			// installFromDolistoreZip may have renamed the row to the canonical
			// descriptor id (see DMMClient::renameRegistryRow). Re-resolve the
			// module_id by following dolistore_id, otherwise the migration step
			// would target the orphaned seed id.
			$canonical = new DMMModule($db);
			$sqlR = "SELECT rowid FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $mod->dolistore_id);
			$resR = $db->query($sqlR);
			if ($resR && $db->num_rows($resR) > 0) {
				$o = $db->fetch_object($resR);
				$canonical->fetch((int) $o->rowid);
				$id = $canonical->id;
				$mod = $canonical;
			}

			// Same auto-migrate vs popup decision tree as the GitHub path.
			$newVersion = $mod->installed_version ?: '?';
			$autoMigrate = dmm_get_setting('auto_migrate', '1');
			if ($autoMigrate === '1') {
				$migrationResult = dmm_run_module_migration($mod->module_id, $db);
				if ($migrationResult) {
					if ($wasInstalled) {
						setEventMessages($langs->transnoentities('DMMUpdateSuccess', $mod->module_id, $previousVersion, $newVersion), null, 'mesgs');
					} else {
						setEventMessages($langs->transnoentities('DMMInstallSuccess', $mod->module_id, $newVersion), null, 'mesgs');
					}
					setEventMessages($langs->trans('DMMModuleMigrated', $mod->module_id), null, 'mesgs');
					dmm_show_setup_link_toast($mod->module_id, $langs);
				} else {
					setEventMessages($langs->trans('DMMModuleMigrateFailed', $mod->module_id), null, 'errors');
				}
			} else {
				if ($wasInstalled) {
					setEventMessages($langs->transnoentities('DMMUpdateSuccess', $mod->module_id, $previousVersion, $newVersion), null, 'mesgs');
				} else {
					setEventMessages($langs->transnoentities('DMMInstallSuccess', $mod->module_id, $newVersion), null, 'mesgs');
				}
				$_SESSION['dmm_pending_migration'] = $mod->module_id;
			}
		} else {
			// "paid_product" means the anonymous endpoint refused because the module
			// is not free. That is not a download failure the user can act on as
			// written — point them at the credentials that would unlock it.
			$msg = $result['message'] ?? 'install failed';
			if (strpos($msg, 'paid_product') !== false) {
				$msg = $langs->trans('DMMDolistorePaidNeedsCreds');
			}
			setEventMessages($msg, null, 'errors');
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
		exit;
	}

	if (!empty($tag)) {
		// Public repos have no associated token — pass null and let the client
		// resolve fallback behavior (anonymous GitHub API calls).
		$plainToken = null;
		if (!empty($mod->fk_dmm_token)) {
			$tokenObj = new DMMToken($db);
			if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
				$plainToken = $tokenObj->getDecryptedToken();
			}
		}

		$result = $dmmClient->installOrUpdate($mod->module_id, $tag, $plainToken, $mod->github_repo, $activeChannel);
		if ($result['success']) {
			$newVersion = ltrim($tag, 'vV');
			if ($mod->installed) {
				setEventMessages($langs->transnoentities('DMMUpdateSuccess', $mod->module_id, $mod->installed_version, $newVersion), null, 'mesgs');
			} else {
				setEventMessages($langs->transnoentities('DMMInstallSuccess', $mod->module_id, $newVersion), null, 'mesgs');
			}

			$autoMigrate = dmm_get_setting('auto_migrate', '1');

			// Self-update: always auto-migrate + redirect
			if ($mod->module_id === 'dolimodulemanager') {
				$modFile = DOL_DOCUMENT_ROOT.'/custom/dolimodulemanager/core/modules/modDoliModuleManager.class.php';
				if (file_exists($modFile)) {
					include_once $modFile;
					$modInstance = new modDoliModuleManager($db);
					$modInstance->init();
				}
				setEventMessages($langs->trans('DMMSelfUpdateDone'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
				exit;
			}

			// Other modules: auto-migrate or prompt
			if ($autoMigrate === '1') {
				$migrationResult = dmm_run_module_migration($mod->module_id, $db);
				if ($migrationResult) {
					setEventMessages($langs->trans('DMMModuleMigrated', $mod->module_id), null, 'mesgs');
					dmm_show_setup_link_toast($mod->module_id, $langs);
				} else {
					setEventMessages($langs->trans('DMMReactivateAdvice'), null, 'warnings');
				}
			} else {
				// Will show popup in the view section
				$_SESSION['dmm_pending_migration'] = $mod->module_id;
			}

			$mod->fetch($id);
		} else {
			setEventMessages($result['message'], null, 'errors');
		}
	}
}

// Run migration
if ($action == 'confirm_migrate' && dmm_user_can('write')) {
	$migrationResult = dmm_run_module_migration($mod->module_id, $db);
	if ($migrationResult) {
		setEventMessages($langs->trans('DMMModuleMigrated', $mod->module_id), null, 'mesgs');
		dmm_show_setup_link_toast($mod->module_id, $langs);
	} else {
		setEventMessages($langs->trans('DMMModuleMigrateFailed', $mod->module_id), null, 'errors');
	}
	unset($_SESSION['dmm_pending_migration']);
	unset($_SESSION['dmm_migration_popup_shown']);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

// Rollback
if ($action == 'confirm_rollback' && dmm_user_can('write')) {
	$backup_id = GETPOSTINT('backup_id');
	if ($backup_id > 0) {
		$backup = new DMMBackup($db);
		$backup->fetch($backup_id);

		$result = $backup->restore();
		if ($result['success']) {
			setEventMessages($langs->transnoentities('DMMRollbackSuccess', $mod->module_id, $backup->version_from), null, 'mesgs');
			setEventMessages($langs->trans('DMMReactivateAdvice'), null, 'warnings');

			// Update registry
			$mod->installed_version = $backup->version_from;
			$mod->invalidateCache();
			$mod->update($user);
			$mod->fetch($id);
		} else {
			setEventMessages($result['message'], null, 'errors');
		}
	}
}

/*
 * View
 */

$title = $langs->trans('DMMModuleDetail').' - '.($mod->name ?: $mod->module_id);

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-module');
dmm_print_ajax_loader_assets();

$linkback = '<a href="'.dol_buildpath('/dolimodulemanager/admin/index.php', 1).'">'.$langs->trans("DMMDashboard").'</a>';
print load_fiche_titre($title, $linkback, 'fa-puzzle-piece');

// Module info table
print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';

print '<tr><td class="titlefield">'.$langs->trans('DMMModuleId').'</td><td>'.dol_escape_htmltag($mod->module_id).'</td></tr>';
print '<tr><td>'.$langs->trans('Name').'</td><td>'.dol_escape_htmltag($mod->name ?: '-').'</td></tr>';
print '<tr><td>'.$langs->trans('Description').'</td><td>'.dol_escape_htmltag($mod->description ?: '-').'</td></tr>';
print '<tr><td>'.$langs->trans('Author').'</td><td>'.dol_escape_htmltag($mod->author ?: '-').'</td></tr>';
print '<tr><td>'.$langs->trans('License').'</td><td>'.dol_escape_htmltag($mod->license ?: '-').'</td></tr>';
if (($mod->source ?? '') === 'dolistore' && !empty($mod->dolistore_id)) {
	$dsUrl = 'https://www.dolistore.com/product.php?id='.((int) $mod->dolistore_id);
	print '<tr><td>'.$langs->trans('DMMSourceURL').'</td><td><a href="'.$dsUrl.'" target="_blank" rel="noopener">DoliStore #'.((int) $mod->dolistore_id).' '.img_picto('', 'fa-external-link-alt', 'class="paddingleft opacitymedium small"').'</a></td></tr>';
} else {
	print '<tr><td>'.$langs->trans('DMMGitHubRepo').'</td><td>'.dol_escape_htmltag($mod->github_repo).'</td></tr>';
}
print '<tr><td>'.$langs->trans('DMMInstalledVersion').'</td><td><strong>'.dol_escape_htmltag($mod->installed_version ?: '-').'</strong></td></tr>';
print '<tr><td>'.$langs->trans('DMMLatestVersion').'</td><td>'.dol_escape_htmltag($mod->cache_latest_version ?: '-').'</td></tr>';
print '<tr><td>'.$langs->trans('DMMCompatibleVersion').'</td><td>'.dol_escape_htmltag($mod->cache_latest_compatible ?: '-').'</td></tr>';
print '<tr><td>'.$langs->trans('DMMLastCheck').'</td><td>'.($mod->cache_last_check ? dol_print_date($mod->cache_last_check, 'dayhour') : $langs->trans('DMMNeverChecked')).'</td></tr>';

$isPrivateNoToken = (!empty($mod->cache_last_error) && strpos($mod->cache_last_error, 'No token') !== false);
$upstreamStatus = (!empty($mod->cache_last_error) && strpos($mod->cache_last_error, 'upstream_status:') === 0)
	? substr($mod->cache_last_error, strlen('upstream_status:'))
	: null;
if ($isPrivateNoToken) {
	print '<tr><td>'.$langs->trans('Status').'</td><td><span class="badge badge-warning">'.$langs->trans('DMMPrivate').'</span> '.$langs->trans('DMMPrivateHelp').'</td></tr>';
	if (!empty($mod->url)) {
		print '<tr><td>'.$langs->trans('DMMGetAccess').'</td><td><a class="butAction butActionSmall" href="'.dol_escape_htmltag($mod->url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($mod->url).'</a></td></tr>';
	}
} elseif ($upstreamStatus !== null) {
	print '<tr><td>'.$langs->trans('DMMUpstreamStatus').'</td><td><span class="badge badge-warning">'.dol_escape_htmltag($upstreamStatus).'</span> <span class="opacitymedium small">'.$langs->trans('DMMUpstreamStatusHelp').'</span></td></tr>';
} elseif (!empty($mod->cache_last_error)) {
	print '<tr><td>'.$langs->trans('Error').'</td><td class="error">'.dol_escape_htmltag($mod->cache_last_error).'</td></tr>';
}

if (!$isPrivateNoToken && !empty($mod->url)) {
	print '<tr><td>'.$langs->trans('URL').'</td><td><a href="'.dol_escape_htmltag($mod->url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($mod->url).'</a></td></tr>';
}

print '</table>';
print '</div>';

// Update channel selector — gated behind global developer mode. Available for any
// git-backed module (not DoliStore), even one with no release: the user can follow
// any branch. The branch list is loaded on demand (AJAX) to avoid an API call on
// every page render.
$isGitBacked = (($mod->source ?? '') !== 'dolistore') && !empty($mod->github_repo) && strpos($mod->github_repo, '/') !== false;
if (dmm_is_dev_mode() && $isGitBacked && dmm_user_can('write')) {
	$currentChannel = $mod->channel ?: 'stable';
	$branchUrl = $_SERVER['PHP_SELF'].'?action=listbranches&token='.newToken().'&id='.$id;
	print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'" class="inline-block" id="dmmChannelForm">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setchannel">';
	print '<label class="paddingright"><strong>'.dmm_label_help($langs->trans('DMMUpdateChannel'), 'DMMChannelTooltip', 'channels').'</strong></label>';
	print '<select name="channel" id="dmmChannelSelect" onchange="this.form.submit()" data-dmm-branches-url="'.dol_escape_htmltag($branchUrl).'">';
	print '<option value="stable"'.($currentChannel === 'stable' ? ' selected' : '').'>'.$langs->trans('DMMChannelStable').'</option>';
	if (!empty($mod->branch_dev)) {
		print '<option value="'.dol_escape_htmltag($mod->branch_dev).'"'.($currentChannel === 'dev' ? ' selected' : '').'>'.dol_escape_htmltag($mod->branch_dev).'</option>';
	}
	print '</select>';
	print ' <a href="#" id="dmmLoadBranches" class="paddingleft">'.$langs->trans('DMMLoadBranches').'</a>';
	print '</form>';
	if ($currentChannel === 'dev') {
		print '<div class="warning small" style="margin-top:6px">'.$langs->trans('DMMChannelDevWarning').'</div>';
	}
	// Populate the <select> with the repo's real branches when the user asks for it.
	$nonce = function_exists('getNonce') ? ' nonce="'.getNonce().'"' : '';
	print '<script'.$nonce.'>
(function () {
	var link = document.getElementById("dmmLoadBranches");
	var select = document.getElementById("dmmChannelSelect");
	if (!link || !select) return;
	link.addEventListener("click", function (e) {
		e.preventDefault();
		link.textContent = "...";
		fetch(select.getAttribute("data-dmm-branches-url") + "&ajax=1", {
			credentials: "same-origin",
			headers: {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"}
		}).then(function (r) { return r.json(); }).then(function (payload) {
			link.textContent = '.json_encode($langs->trans('DMMLoadBranches')).';
			if (!payload || payload.success !== true || !Array.isArray(payload.branches)) {
				alert(payload && payload.error ? payload.error : '.json_encode($langs->trans('DMMNoBranchesFound')).');
				return;
			}
			var current = select.value;
			while (select.options.length > 1) { select.remove(1); }
			payload.branches.forEach(function (b) {
				var opt = document.createElement("option");
				opt.value = b.name;
				opt.textContent = b.name;
				if (b.current || b.name === current) opt.selected = true;
				select.add(opt);
			});
		}).catch(function () {
			link.textContent = '.json_encode($langs->trans('DMMLoadBranches')).';
			alert('.json_encode($langs->trans('DMMNoBranchesFound')).');
		});
	});
}());
</script>';
}

// Compatibility matrix from cached manifest
if (!empty($mod->cache_manifest_json)) {
	$manifest = json_decode($mod->cache_manifest_json, true);
	if (!empty($manifest['compatibility']) && is_array($manifest['compatibility'])) {
		print '<br><h3>'.$langs->trans('DMMCompatibilityMatrix').'</h3>';
		print '<div class="div-table-responsive">';
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>Version</td>';
		print '<td>'.$langs->trans('DMMDolibarrMin').'</td>';
		print '<td>'.$langs->trans('DMMDolibarrMax').'</td>';
		print '<td>'.$langs->trans('DMMPhpMin').'</td>';
		print '<td>'.$langs->trans('DMMPhpMax').'</td>';
		print '</tr>';

		foreach ($manifest['compatibility'] as $ver => $compat) {
			print '<tr class="oddeven">';
			print '<td><strong>'.dol_escape_htmltag($ver).'</strong></td>';
			print '<td>'.dol_escape_htmltag($compat['dolibarr_min'] ?? '-').'</td>';
			print '<td>'.dol_escape_htmltag($compat['dolibarr_max'] ?? '-').'</td>';
			print '<td>'.dol_escape_htmltag($compat['php_min'] ?? '-').'</td>';
			print '<td>'.dol_escape_htmltag($compat['php_max'] ?? '*').'</td>';
			print '</tr>';
		}
		print '</table>';
		print '</div>';
	}
}

// Changelog
if (!empty($mod->cache_changelog)) {
	// DB stores newlines as literal \n (2 chars) — convert back to real newlines
	$changelog = preg_replace('/\x5cr\x5cn|\x5cn|\x5cr/', "\n", $mod->cache_changelog);
	$changelog = preg_replace('/<!--\s*dmm[\s\S]*?-->/i', '', $changelog);
	$changelog = trim($changelog);

	if (!empty($changelog)) {
		print '<br><h3>'.$langs->trans('DMMChangelog').'</h3>';
		print '<div class="small" style="padding:8px; background:#f8f8f8; border:1px solid #e0e0e0; border-radius:4px;">'.nl2br(dol_escape_htmltag($changelog, 0, 1)).'</div>';
	}
}

// Action buttons
print '<div class="tabsAction">';

print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMCheckNow')).' href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=checkupdate&token='.newToken().'">'.$langs->trans('DMMCheckNow').'</a>';

// DoliStore products don't publish a version through the catalog (the web listing
// has no module_version at all), so cache_latest_compatible stays empty and the
// version-gated block below would never offer a button. The ZIP itself carries the
// version, which installFromDolistoreZip() reads after downloading — so offer the
// action unconditionally for a DoliStore row and let the pipeline resolve it.
$isDolistoreRow = (($mod->source ?? '') === 'dolistore') && !empty($mod->dolistore_id);
if (dmm_user_can('write') && $isDolistoreRow && empty($mod->cache_latest_compatible)) {
	$dsLabel = $mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall');
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'">'.$dsLabel.'</a>';
}

if (dmm_user_can('write') && !empty($mod->cache_latest_compatible)) {
	$onDevChannel = dmm_module_tracks_branch($mod);
	$canInstall = !$mod->installed;
	if ($onDevChannel) {
		// version_compare doesn't understand "dev:<sha>" strings, so the regular
		// canUpdate check always returns false on the dev channel and the Update
		// button never appears. Treat any SHA mismatch (or any installed semver
		// that doesn't match dev:<sha>) as an update offer — the user explicitly
		// opted into branch-HEAD tracking.
		$canUpdate = $mod->installed && $mod->installed_version !== $mod->cache_latest_compatible;
	} else {
		$canUpdate = $mod->installed && $mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>');
	}

	if ($upstreamStatus !== null) {
		// Upstream author marked this as non-enabled (soon, beta, deprecated...).
		// Only expose install when dev mode is on — the import step ensures that
		// these rows are only present while dev mode is on anyway, but we double-gate.
		if (dmm_is_dev_mode() && ($canInstall || $canUpdate)) {
			$actionLabel = $mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall');
			print '<a class="butAction butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('DMMInstallAnyway')).'">'.$langs->trans('DMMInstallAnyway').' ('.dol_escape_htmltag($upstreamStatus).') v'.$mod->cache_latest_compatible.'</a>';
		}
	} else {
		// On dev channel `cache_latest_compatible` is a "dev:<sha>" pseudo-version —
		// don't prefix it with "v". Show the branch + short SHA instead.
		$labelVersion = $onDevChannel
			? dol_escape_htmltag($mod->branch_dev.'@'.substr((string) $mod->cache_latest_compatible, 4))
			: 'v'.dol_escape_htmltag($mod->cache_latest_compatible);
		if ($canInstall) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'">'.$langs->trans('DMMInstall').' '.$labelVersion.'</a>';
		}
		if ($canUpdate) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'">'.$langs->trans('DMMUpdate').' '.$labelVersion.'</a>';
		}
	}
}

print '</div>';

// Install/Update confirmation dialog
if ($action == 'confirminstall') {
	$onDevChannel = dmm_module_tracks_branch($mod);
	$newVersion = $mod->cache_latest_compatible ?: '?';
	$displayVersion = $onDevChannel ? $mod->branch_dev.'@'.substr((string) $newVersion, 4) : $newVersion;
	if ($mod->installed && $mod->installed_version) {
		$msg = $langs->transnoentities('DMMConfirmUpdate', $mod->module_id, $mod->installed_version, $displayVersion);
	} else {
		$msg = $langs->transnoentities('DMMConfirmInstall', $mod->module_id, $displayVersion);
	}
	// On dev channel the install handler resolves `tag` itself from $mod->branch_dev
	// when empty — pass nothing rather than the "vdev:<sha>" string which GitHub
	// would reject as a non-existent ref.
	$tagParam = $onDevChannel ? '' : '&tag=v'.$newVersion;
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id.$tagParam,
		$mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall'),
		$msg,
		'confirm_install',
		'',
		0,
		1
	);
}

// Migration popup after install/update
if (!empty($_SESSION['dmm_pending_migration']) && $_SESSION['dmm_pending_migration'] === $mod->module_id) {
	if (!empty($_SESSION['dmm_migration_popup_shown'])) {
		// Popup was shown before but user didn't confirm — they cancelled, clear it
		unset($_SESSION['dmm_pending_migration']);
		unset($_SESSION['dmm_migration_popup_shown']);
		setEventMessages($langs->trans('DMMReactivateAdvice'), null, 'warnings');
	} else {
		$_SESSION['dmm_migration_popup_shown'] = 1;
		print $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$id,
			$langs->trans('DMMRunMigration'),
			$langs->trans('DMMConfirmMigration', $mod->module_id),
			'confirm_migrate',
			'',
			0,
			1
		);
	}
}

// Backups for this module
$backupObj = new DMMBackup($db);
$backups = $backupObj->fetchAll($mod->id);

if (!empty($backups)) {
	print '<br><h3>'.$langs->trans('DMMBackups').'</h3>';
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('DMMVersionFrom').'</td>';
	print '<td>'.$langs->trans('DMMVersionTo').'</td>';
	print '<td>'.$langs->trans('DMMBackupDate').'</td>';
	print '<td>'.$langs->trans('DMMBackupSize').'</td>';
	print '<td class="center">'.$langs->trans('DMMBackupStatus').'</td>';
	print '<td class="center">'.$langs->trans('Action').'</td>';
	print '</tr>';

	foreach ($backups as $b) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($b->version_from).'</td>';
		print '<td>'.dol_escape_htmltag($b->version_to).'</td>';
		print '<td>'.dol_print_date($b->date_creation, 'dayhour').'</td>';
		print '<td>'.($b->backup_size ? dol_print_size($b->backup_size, 0) : '-').'</td>';
		print '<td class="center">'.dol_escape_htmltag($b->status).'</td>';
		print '<td class="center">';
		if ($b->status === 'ok' && dmm_user_can('write')) {
			print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirmrollback&token='.newToken().'&backup_id='.$b->id.'" title="'.$langs->trans('DMMRollback').'">'.img_picto($langs->trans('DMMRollback'), 'fa-undo').'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

// Rollback confirmation
if ($action == 'confirmrollback') {
	$backup_id = GETPOSTINT('backup_id');
	$b = new DMMBackup($db);
	$b->fetch($backup_id);
	$msg = $langs->transnoentities('DMMConfirmRollback', $mod->module_id, $b->version_from);
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id.'&backup_id='.$backup_id,
		$langs->trans('DMMRollback'),
		$msg,
		'confirm_rollback',
		'',
		0,
		1
	);
}

llxFooter();
$db->close();
