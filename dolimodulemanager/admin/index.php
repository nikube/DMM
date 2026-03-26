<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/index.php
 * \ingroup dolimodulemanager
 * \brief   Dashboard — module catalog, updates, add repo
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

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->hasRight('dolimodulemanager', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$filter = GETPOST('filter', 'alpha') ?: 'all';

$dmmModule = new DMMModule($db);
$dmmToken = new DMMToken($db);
$dmmClient = new DMMClient($db);
$form = new Form($db);

/*
 * Actions
 */

// Remove module from registry
if ($action == 'confirm_removemodule' && $id > 0 && $user->hasRight('dolimodulemanager', 'write')) {
	$mod = new DMMModule($db);
	$mod->fetch($id);
	$mod->delete($user);
	setEventMessages('Module removed from registry', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

// Check for updates (single module)
if ($action == 'checkupdate' && $id > 0) {
	$mod = new DMMModule($db);
	$mod->fetch($id);
	$tokenObj = new DMMToken($db);
	$tokenObj->fetch($mod->fk_dmm_token);
	$dmmClient->checkUpdate($mod->module_id, $tokenObj->getDecryptedToken(), $mod->github_repo);
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

// Check all modules
if ($action == 'checkall') {
	$allMods = $dmmModule->fetchAll();
	foreach ($allMods as $mod) {
		$tokenObj = new DMMToken($db);
		$tokenObj->fetch($mod->fk_dmm_token);
		$dmmClient->checkUpdate($mod->module_id, $tokenObj->getDecryptedToken(), $mod->github_repo);
	}
	setEventMessages('Checked '.count($allMods).' modules', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

/*
 * Auto-check updates on page load (if enabled)
 */
dmm_auto_check_updates();

/*
 * View
 */

$title = $langs->trans('DMMDashboard');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-index');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager'), $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'dashboard', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- First-run: redirect to preflight ----
$firstRun = dmm_get_setting('first_run_done', '0');
if ($firstRun !== '1') {
	dmm_set_setting('first_run_done', '1');
	// Redirect to preflight web page
	$preflightUrl = dol_buildpath('/dolimodulemanager/dmm_preflight_web.php', 1);
	header('Location: '.$preflightUrl);
	exit;
}

// ---- Permission check banner ----
$customDir = DOL_DOCUMENT_ROOT.'/custom';
$phpUser = function_exists('posix_geteuid') ? (@posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : 'www-data';
$permProblems = array();
if (is_dir($customDir)) {
	$dirs = array_filter(scandir($customDir), function ($d) use ($customDir) {
		return $d[0] !== '.' && is_dir($customDir.'/'.$d);
	});
	foreach ($dirs as $d) {
		$path = $customDir.'/'.$d;
		// Check dir itself AND sample files inside
		$hasIssue = false;
		if (!is_writable($path)) {
			$hasIssue = true;
		} else {
			$files = @glob($path.'/*');
			foreach (array_slice($files ?: array(), 0, 5) as $f) {
				if (!is_writable($f)) {
					$hasIssue = true;
					break;
				}
			}
		}
		if ($hasIssue) {
			$permProblems[] = $d;
		}
	}
}
if (!empty($permProblems)) {
	print '<div class="warning">';
	print img_picto('', 'fa-exclamation-triangle', 'class="pictofixedwidth"');
	print '<strong>'.$langs->trans('DMMPermissionWarning').'</strong><br>';
	print $langs->trans('DMMPermissionWarningDetail', implode(', ', $permProblems)).'<br>';
	print '<code>chown -R '.$phpUser.':'.$phpUser.' '.$customDir.'/ && chmod -R u+w '.$customDir.'/</code>';
	print '</div><br>';
}

// Load data
$allModules = $dmmModule->fetchAll();
$modulesWithUpdates = $dmmModule->fetchAll('updates');
$installedCount = 0;
foreach ($allModules as $m) {
	if ($m->installed) {
		$installedCount++;
	}
}

// ---- Summary boxes ----
print '<div class="fichecenter">';
print '<div class="fichethirdleft">';
print '<div class="info-box"><span class="info-box-icon bg-infobox-project">'.img_picto('', 'fa-puzzle-piece', 'class="fa-2x"').'</span>';
print '<div class="info-box-content"><span class="info-box-text">'.$langs->trans('DMMModulesManaged').'</span>';
print '<span class="info-box-number">'.count($allModules).' ('.$installedCount.' installed)</span></div></div>';
print '</div><div class="fichethirdleft">';
$updateClass = count($modulesWithUpdates) > 0 ? 'bg-infobox-action' : 'bg-infobox-project';
print '<div class="info-box"><span class="info-box-icon '.$updateClass.'">'.img_picto('', 'fa-arrow-circle-up', 'class="fa-2x"').'</span>';
print '<div class="info-box-content"><span class="info-box-text">'.$langs->trans('DMMUpdatesAvailable').'</span>';
print '<span class="info-box-number">'.count($modulesWithUpdates).'</span></div></div>';
print '</div></div>';
print '<div class="clearboth"></div>';

// ---- Action buttons ----
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=checkall&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMCheckAllNow').'</a>';
print '</div>';

// ---- Filter tabs ----
print '<div class="tabs" data-role="controlgroup" data-type="horizontal">';
$filters = array('all' => 'DMMFilterAll', 'installed' => 'DMMFilterInstalled', 'updates' => 'DMMFilterUpdates', 'notinstalled' => 'DMMNotInstalled');
foreach ($filters as $fkey => $flabel) {
	$active = ($filter === $fkey) ? ' inline-block tabactive' : ' inline-block';
	print '<div class="'.$active.'"><a class="tab" href="'.$_SERVER['PHP_SELF'].'?filter='.$fkey.'">'.$langs->trans($flabel).'</a></div>';
}
print '</div><div class="clearboth"></div>';

// ---- Module list ----
$modules = $dmmModule->fetchAll($filter);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DMMModuleId').'</td>';
print '<td class="tdoverflowmax150">'.$langs->trans('Name').'</td>';
print '<td class="tdoverflowmax200">'.$langs->trans('DMMGitHubRepo').'</td>';
print '<td class="center">'.$langs->trans('DMMInstalledVersion').'</td>';
print '<td class="center">'.$langs->trans('DMMCompatibleVersion').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($modules)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('DMMNoModules').'</td></tr>';
}

foreach ($modules as $mod) {
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax100"><a href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'">'.dol_escape_htmltag($mod->module_id).'</a></td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag($mod->name ?: '-').'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag($mod->github_repo).'</td>';
	print '<td class="center">'.($mod->installed_version ?: '-').'</td>';
	print '<td class="center">'.($mod->cache_latest_compatible ?: '-').'</td>';

	// Status
	print '<td class="center nowraponall">';
	if (!empty($mod->cache_last_error)) {
		print '<span class="badge badge-danger" title="'.dol_escape_htmltag($mod->cache_last_error).'">Error</span>';
	} elseif (!$mod->installed) {
		print '<span class="badge badge-secondary">'.$langs->trans('DMMNotInstalled').'</span>';
	} elseif ($mod->cache_latest_compatible && $mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>')) {
		print '<span class="badge badge-warning">'.$langs->trans('DMMUpdateAvailable').'</span>';
	} elseif ($mod->installed) {
		print '<span class="badge badge-status4">'.$langs->trans('DMMUpToDate').'</span>';
	}
	print '</td>';

	// Actions
	print '<td class="center nowraponall">';
	print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=checkupdate&token='.newToken().'&id='.$mod->id.'&filter='.$filter.'" title="'.$langs->trans('DMMCheckNow').'">'.img_picto($langs->trans('DMMCheckNow'), 'fa-sync').'</a>';
	if ($user->hasRight('dolimodulemanager', 'write')) {
		if ($mod->cache_latest_compatible && (!$mod->installed || ($mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>')))) {
			$actionLabel = $mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall');
			print '<a class="paddingright" href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'&action=confirminstall&token='.newToken().'" title="'.$actionLabel.'">'.img_picto($actionLabel, 'fa-download').'</a>';
		}
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=removemodule&token='.newToken().'&id='.$mod->id.'&filter='.$filter.'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>';
	}
	print '</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Remove confirmation
if ($action == 'removemodule' && $id > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id.'&filter='.$filter,
		$langs->trans('Delete'),
		'Remove this module from the registry? (The module files will NOT be deleted.)',
		'confirm_removemodule',
		'',
		0,
		1
	);
}

print dol_get_fiche_end();

llxFooter();
$db->close();
