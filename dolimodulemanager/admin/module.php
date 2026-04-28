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
dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->hasRight('dolimodulemanager', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

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
}

// Switch update channel for this module (only if developer mode is on AND branch_dev is known).
if ($action == 'setchannel' && $user->hasRight('dolimodulemanager', 'write') && dmm_is_dev_mode()) {
	$newChannel = GETPOST('channel', 'alphanohtml');
	if (in_array($newChannel, array('stable', 'dev'), true)) {
		if ($newChannel === 'dev' && empty($mod->branch_dev)) {
			setEventMessages($langs->trans('DMMNoBranchDev'), null, 'warnings');
		} else {
			$mod->channel = $newChannel;
			$mod->invalidateCache();
			$mod->update($user);
			setEventMessages($langs->trans('DMMChannelSwitched'), null, 'mesgs');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

// Install or update
if ($action == 'confirm_install' && $user->hasRight('dolimodulemanager', 'write')) {
	$tag = GETPOST('tag', 'alphanohtml');
	$activeChannel = ($mod->channel === 'dev' && dmm_is_dev_mode() && !empty($mod->branch_dev)) ? 'dev' : 'stable';
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
		$result = $dmmClient->installFromDolistoreZip($mod->module_id, (int) $mod->dolistore_id);
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

			$newVersion = $mod->installed_version ?: '?';
			if ($mod->installed) {
				setEventMessages($langs->transnoentities('DMMUpdateSuccess', $mod->module_id, $mod->installed_version, $newVersion), null, 'mesgs');
			} else {
				setEventMessages($langs->transnoentities('DMMInstallSuccess', $mod->module_id, $newVersion), null, 'mesgs');
			}

			// Same auto-migrate vs popup decision tree as the GitHub path.
			$autoMigrate = dmm_get_setting('auto_migrate', '0');
			if ($autoMigrate === '1') {
				$migrationResult = dmm_run_module_migration($mod->module_id, $db);
				if ($migrationResult) {
					setEventMessages($langs->trans('DMMModuleMigrated', $mod->module_id), null, 'mesgs');
				} else {
					setEventMessages($langs->trans('DMMReactivateAdvice'), null, 'warnings');
				}
			} else {
				$_SESSION['dmm_pending_migration'] = $mod->module_id;
			}
		} else {
			setEventMessages($result['message'] ?? 'install failed', null, 'errors');
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

			$autoMigrate = dmm_get_setting('auto_migrate', '0');

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
if ($action == 'confirm_migrate' && $user->hasRight('dolimodulemanager', 'write')) {
	$migrationResult = dmm_run_module_migration($mod->module_id, $db);
	if ($migrationResult) {
		setEventMessages($langs->trans('DMMModuleMigrated', $mod->module_id), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('DMMModuleMigrateFailed', $mod->module_id), null, 'errors');
	}
	unset($_SESSION['dmm_pending_migration']);
	unset($_SESSION['dmm_migration_popup_shown']);
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
	exit;
}

// Rollback
if ($action == 'confirm_rollback' && $user->hasRight('dolimodulemanager', 'write')) {
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

// Update channel selector — gated behind global developer mode AND a declared branch_dev.
if (dmm_is_dev_mode() && !empty($mod->branch_dev) && $user->hasRight('dolimodulemanager', 'write')) {
	$currentChannel = $mod->channel ?: 'stable';
	print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.$id.'" class="inline-block">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setchannel">';
	print '<label class="paddingright"><strong>'.$langs->trans('DMMUpdateChannel').'</strong></label>';
	print '<select name="channel" onchange="this.form.submit()">';
	print '<option value="stable"'.($currentChannel === 'stable' ? ' selected' : '').'>'.$langs->trans('DMMChannelStable').'</option>';
	print '<option value="dev"'.($currentChannel === 'dev' ? ' selected' : '').'>'.$langs->trans('DMMChannelDev').' ('.dol_escape_htmltag($mod->branch_dev).')</option>';
	print '</select>';
	print '</form>';
	if ($currentChannel === 'dev') {
		print '<div class="warning small" style="margin-top:6px">'.$langs->trans('DMMChannelDevWarning').'</div>';
	}
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

print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=checkupdate&token='.newToken().'">'.$langs->trans('DMMCheckNow').'</a>';

if ($user->hasRight('dolimodulemanager', 'write') && !empty($mod->cache_latest_compatible)) {
	$canInstall = !$mod->installed;
	$canUpdate = $mod->installed && $mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>');

	if ($upstreamStatus !== null) {
		// Upstream author marked this as non-enabled (soon, beta, deprecated...).
		// Only expose install when dev mode is on — the import step ensures that
		// these rows are only present while dev mode is on anyway, but we double-gate.
		if (dmm_is_dev_mode() && ($canInstall || $canUpdate)) {
			$actionLabel = $mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall');
			print '<a class="butAction butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('DMMInstallAnyway')).'">'.$langs->trans('DMMInstallAnyway').' ('.dol_escape_htmltag($upstreamStatus).') v'.$mod->cache_latest_compatible.'</a>';
		}
	} else {
		if ($canInstall) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'">'.$langs->trans('DMMInstall').' v'.$mod->cache_latest_compatible.'</a>';
		}
		if ($canUpdate) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=confirminstall&token='.newToken().'">'.$langs->trans('DMMUpdate').' v'.$mod->cache_latest_compatible.'</a>';
		}
	}
}

print '</div>';

// Install/Update confirmation dialog
if ($action == 'confirminstall') {
	$newVersion = $mod->cache_latest_compatible ?: '?';
	if ($mod->installed && $mod->installed_version) {
		$msg = $langs->transnoentities('DMMConfirmUpdate', $mod->module_id, $mod->installed_version, $newVersion);
	} else {
		$msg = $langs->transnoentities('DMMConfirmInstall', $mod->module_id, $newVersion);
	}
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id.'&tag=v'.$newVersion,
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
		if ($b->status === 'ok' && $user->hasRight('dolimodulemanager', 'write')) {
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
