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
 * \brief   Dashboard page
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
$dmmModule = new DMMModule($db);
$dmmToken = new DMMToken($db);
$dmmClient = new DMMClient($db);

/*
 * Actions
 */

// Check all modules
if ($action == 'checkall') {
	$allMods = $dmmModule->fetchAll();
	$checked = 0;
	foreach ($allMods as $mod) {
		$tokenObj = new DMMToken($db);
		$tokenObj->fetch($mod->fk_dmm_token);
		$plainToken = $tokenObj->getDecryptedToken();

		$dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
		$checked++;
	}
	setEventMessages('Checked '.$checked.' modules', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMDashboard');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-index');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'dashboard', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// Load data
$allModules = $dmmModule->fetchAll();
$installedModules = $dmmModule->fetchAll('installed');
$modulesWithUpdates = $dmmModule->fetchAll('updates');
$allTokens = $dmmToken->fetchAll();

// Summary boxes
print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// Modules managed
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-project">'.img_picto('', 'fa-puzzle-piece', 'class="fa-2x"').'</span>';
print '<div class="info-box-content">';
print '<span class="info-box-text">'.$langs->trans('DMMModulesManaged').'</span>';
print '<span class="info-box-number">'.count($allModules).' ('.count($installedModules).' installed)</span>';
print '</div>';
print '</div>';

print '</div>';
print '<div class="fichethirdleft">';

// Updates available
$updateClass = count($modulesWithUpdates) > 0 ? 'bg-infobox-action' : 'bg-infobox-project';
print '<div class="info-box">';
print '<span class="info-box-icon '.$updateClass.'">'.img_picto('', 'fa-arrow-circle-up', 'class="fa-2x"').'</span>';
print '<div class="info-box-content">';
print '<span class="info-box-text">'.$langs->trans('DMMUpdatesAvailable').'</span>';
print '<span class="info-box-number">'.count($modulesWithUpdates).'</span>';
print '</div>';
print '</div>';

print '</div>';
print '<div class="fichethirdleft">';

// Tokens
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-project">'.img_picto('', 'fa-key', 'class="fa-2x"').'</span>';
print '<div class="info-box-content">';
print '<span class="info-box-text">'.$langs->trans('DMMTokens').'</span>';
print '<span class="info-box-number">'.count($allTokens).'</span>';
print '</div>';
print '</div>';

print '</div>';
print '</div>';
print '<div class="clearboth"></div>';

// Action buttons
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=checkall&token='.newToken().'">'.$langs->trans('DMMCheckAllNow').'</a>';
print '</div>';

// Modules with pending updates
if (!empty($modulesWithUpdates)) {
	print '<h3>'.$langs->trans('DMMModulesWithUpdates').'</h3>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('DMMModuleId').'</td>';
	print '<td>'.$langs->trans('Name').'</td>';
	print '<td class="center">'.$langs->trans('DMMInstalledVersion').'</td>';
	print '<td class="center">'.$langs->trans('DMMCompatibleVersion').'</td>';
	print '<td class="center">'.$langs->trans('Action').'</td>';
	print '</tr>';

	foreach ($modulesWithUpdates as $mod) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'">'.dol_escape_htmltag($mod->module_id).'</a></td>';
		print '<td>'.dol_escape_htmltag($mod->name ?: '-').'</td>';
		print '<td class="center">'.dol_escape_htmltag($mod->installed_version).'</td>';
		print '<td class="center"><strong>'.dol_escape_htmltag($mod->cache_latest_compatible).'</strong></td>';
		print '<td class="center">';
		if ($user->hasRight('dolimodulemanager', 'write')) {
			print '<a class="butActionSmall" href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'&action=confirminstall&token='.newToken().'">'.$langs->trans('DMMUpdate').'</a>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
} else {
	print '<br><div class="opacitymedium">'.$langs->trans('DMMNoUpdates').'</div>';
}

// Token health
if (!empty($allTokens)) {
	print '<br><h3>'.$langs->trans('DMMTokenHealth').'</h3>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('DMMTokenLabel').'</td>';
	print '<td class="center">'.$langs->trans('DMMTokenStatus').'</td>';
	print '<td class="center">'.$langs->trans('DMMTokenLastValidated').'</td>';
	print '</tr>';

	foreach ($allTokens as $t) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($t->label).'</td>';
		print '<td class="center">';
		if ($t->status) {
			print '<span class="badge badge-status4">'.$langs->trans('DMMTokenActive').'</span>';
		} else {
			print '<span class="badge badge-secondary">'.$langs->trans('DMMTokenDisabled').'</span>';
		}
		print '</td>';
		print '<td class="center">'.($t->last_validated ? dol_print_date($t->last_validated, 'dayhour') : '-').'</td>';
		print '</tr>';
	}
	print '</table>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
