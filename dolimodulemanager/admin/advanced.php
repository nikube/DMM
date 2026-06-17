<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/advanced.php
 * \ingroup dolimodulemanager
 * \brief   Advanced settings: general options, developer mode, community YAML,
 *          local module scan and backups management.
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

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

$form = new Form($db);

/*
 * Actions
 */

// Save general settings
if ($action == 'savesettings') {
	dmm_set_setting('auto_check', GETPOST('auto_check', 'int') ? '1' : '0');
	dmm_set_setting('auto_migrate', GETPOST('auto_migrate', 'int') ? '1' : '0');
	dmm_set_setting('check_interval', GETPOST('check_interval', 'int'));
	dmm_set_setting('backup_retention_days', GETPOST('backup_retention_days', 'int'));
	dmm_set_setting('backup_retention_count', GETPOST('backup_retention_count', 'int'));
	dmm_set_setting('notify_email', GETPOST('notify_email', 'restricthtml'));
	dmm_set_setting('temp_dir', GETPOST('temp_dir', 'restricthtml'));
	dmm_set_setting('dev_mode_enabled', GETPOST('dev_mode_enabled', 'int') ? '1' : '0');
	dmm_set_setting('community_yaml_enabled', GETPOST('community_yaml_enabled', 'int') ? '1' : '0');
	$communityUrl = trim((string) GETPOST('community_yaml_url', 'restricthtml'));
	if ($communityUrl === '') {
		// Empty input = restore default so users can recover from a bad custom URL.
		$communityUrl = 'https://raw.githubusercontent.com/Dolibarr/dolibarr-community-modules/main/index.yaml';
	}
	dmm_set_setting('community_yaml_url', $communityUrl);

	setEventMessages($langs->trans('DMMSettingsSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Scan local custom/ for pre-installed modules and register matchable ones
if ($action == 'scanlocal' && dmm_user_can('write')) {
	dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
	$client = new DMMClient($db);
	$report = $client->scanLocalModules();
	dmm_show_localscan_report($report, $langs);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Restore backup
if ($action == 'confirm_restore' && $id > 0 && dmm_user_can('write')) {
	dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
	dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
	$backupObj = new DMMBackup($db);
	$backupObj->fetch($id);
	$result = $backupObj->restore();
	if ($result['success']) {
		setEventMessages($langs->trans('DMMBackupRestored'), null, 'mesgs');
		$mod = new DMMModule($db);
		if ($mod->fetch(0, $backupObj->module_id) > 0) {
			$mod->installed_version = $backupObj->version_from;
			$mod->invalidateCache();
			$mod->update($user);
		}
	} else {
		setEventMessages($result['message'], null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Delete backup
if ($action == 'confirm_deletebackup' && $id > 0 && dmm_user_can('admin')) {
	dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
	$backupObj = new DMMBackup($db);
	$backupObj->fetch($id);
	$backupObj->delete($user, true);
	setEventMessages($langs->trans('DMMBackupDeleted'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Cleanup old backups
if ($action == 'cleanup' && dmm_user_can('admin')) {
	dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
	$backupObj = new DMMBackup($db);
	$removed = $backupObj->cleanup();
	setEventMessages($langs->trans('DMMCleanedUpBackups', $removed), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMAdvancedTab');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-advanced');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead('advanced');
print dol_get_fiche_head($head, 'advanced', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- General settings ----
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesettings">';

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMGeneralSettings').'</td></tr>';

$autoCheck = dmm_get_setting('auto_check', '1');
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMAutoCheck').'</td>';
print '<td><input type="checkbox" name="auto_check" value="1"'.($autoCheck === '1' ? ' checked' : '').'> '.$langs->trans('DMMAutoCheckHelp').'</td></tr>';

$autoMigrate = dmm_get_setting('auto_migrate', '1');
print '<tr class="oddeven"><td>'.$langs->trans('DMMAutoMigrate').'</td>';
print '<td><input type="checkbox" name="auto_migrate" value="1"'.($autoMigrate === '1' ? ' checked' : '').'> '.$langs->trans('DMMAutoMigrateHelp').'</td></tr>';

$checkInterval = dmm_get_setting('check_interval', '86400');
print '<tr class="oddeven"><td>'.$langs->trans('DMMCheckInterval').'</td>';
print '<td><select name="check_interval">';
$intervals = array(3600 => '1 hour', 21600 => '6 hours', 43200 => '12 hours', 86400 => '24 hours', 604800 => '7 days');
foreach ($intervals as $val => $label) {
	print '<option value="'.$val.'"'.($checkInterval == $val ? ' selected' : '').'>'.$label.'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMBackupRetentionDays').'</td>';
print '<td><input type="number" name="backup_retention_days" value="'.dol_escape_htmltag(dmm_get_setting('backup_retention_days', '30')).'" min="1" max="365" class="width75"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMBackupRetentionCount').'</td>';
print '<td><input type="number" name="backup_retention_count" value="'.dol_escape_htmltag(dmm_get_setting('backup_retention_count', '5')).'" min="1" max="50" class="width75"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMNotifyEmail').'</td>';
print '<td><input type="email" name="notify_email" value="'.dol_escape_htmltag(dmm_get_setting('notify_email', '')).'" class="minwidth300"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTempDir').'</td>';
print '<td><input type="text" name="temp_dir" value="'.dol_escape_htmltag(dmm_get_setting('temp_dir', '')).'" class="minwidth400" placeholder="'.dol_escape_htmltag(DOL_DATA_ROOT.'/dolimodulemanager/temp').'"></td></tr>';

// Developer options
$devModeOn = dmm_is_dev_mode();
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMDeveloperOptions').'</td></tr>';
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMDeveloperMode').'</td>';
print '<td><input type="checkbox" name="dev_mode_enabled" value="1"'.($devModeOn ? ' checked' : '').'> '.$langs->trans('DMMDeveloperModeHelp').'</td></tr>';

// Dolibarr Community Modules
$communityCfg = dmm_get_community_yaml_config();
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMCommunityYAML').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DMMCommunityYAMLEnabled').'</td>';
print '<td><input type="checkbox" name="community_yaml_enabled" value="1"'.($communityCfg['enabled'] ? ' checked' : '').'> '.$langs->trans('DMMCommunityYAMLEnabledHelp').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DMMCommunityYAMLURL').'</td>';
print '<td><input type="text" name="community_yaml_url" value="'.dol_escape_htmltag($communityCfg['url']).'" class="minwidth400 maxwidth600"></td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// ---- Local module scan ----
print '<br>';
print '<h3>'.$langs->trans('DMMScanLocal').'</h3>';
print '<div class="opacitymedium small">'.$langs->trans('DMMScanLocalHelp').'</div>';
if (dmm_user_can('write')) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=scanlocal&token='.newToken().'">'.img_picto('', 'fa-search', 'class="pictofixedwidth"').$langs->trans('DMMScanLocal').'</a>';
	print '</div>';
}

// ---- Backups ----
dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
$backupObj = new DMMBackup($db);
$backups = $backupObj->fetchAll();

// Calculate total storage
$totalBackupSize = 0;
foreach ($backups as $b) {
	$totalBackupSize += ($b->backup_size ?: 0);
}

print '<br>';
print '<h3>'.$langs->trans('DMMBackupsTab');
if ($totalBackupSize > 0) {
	print ' <span class="opacitymedium small">('.dmm_format_size($totalBackupSize).')</span>';
}
print '</h3>';

if (dmm_user_can('admin') && !empty($backups)) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=cleanup&token='.newToken().'">'.$langs->trans('DMMCleanupBackups').'</a>';
	print '</div>';
}

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DMMModuleId').'</td>';
print '<td>'.$langs->trans('DMMVersionFrom').'</td>';
print '<td>'.$langs->trans('DMMVersionTo').'</td>';
print '<td>'.$langs->trans('DMMBackupDate').'</td>';
print '<td>'.$langs->trans('DMMBackupSize').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($backups)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('DMMNoBackups').'</td></tr>';
}

foreach ($backups as $b) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($b->module_id).'</td>';
	print '<td>'.dol_escape_htmltag($b->version_from).'</td>';
	print '<td>'.dol_escape_htmltag($b->version_to).'</td>';
	print '<td>'.dol_print_date($b->date_creation, 'dayhour').'</td>';
	print '<td>'.($b->backup_size ? dmm_format_size($b->backup_size) : '-').'</td>';
	print '<td class="center">';
	if ($b->status === 'ok') {
		print '<span class="badge badge-status4">'.$langs->trans('DMMBackupStatusOk').'</span>';
	} elseif ($b->status === 'restored') {
		print '<span class="badge badge-info">'.$langs->trans('DMMBackupStatusRestored').'</span>';
	} else {
		print '<span class="badge badge-secondary">'.dol_escape_htmltag($b->status).'</span>';
	}
	print '</td>';
	print '<td class="center nowraponall">';
	if ($b->status === 'ok' && dmm_user_can('write')) {
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=restorebackup&token='.newToken().'&id='.$b->id.'" title="'.$langs->trans('DMMRestore').'">'.img_picto($langs->trans('DMMRestore'), 'fa-undo').'</a>';
	}
	if (dmm_user_can('admin')) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=deletebackup&token='.newToken().'&id='.$b->id.'" title="'.$langs->trans('DMMDelete').'">'.img_picto($langs->trans('DMMDelete'), 'delete').'</a>';
	}
	print '</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Restore confirmation
if ($action == 'restorebackup' && $id > 0) {
	$b = new DMMBackup($db);
	$b->fetch($id);
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('DMMRestore'),
		$langs->transnoentities('DMMConfirmRestore', $b->module_id, $b->version_from),
		'confirm_restore', '', 0, 1
	);
}

// Delete confirmation
if ($action == 'deletebackup' && $id > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('DMMDelete'),
		$langs->trans('DMMConfirmDeleteBackup'),
		'confirm_deletebackup', '', 0, 1
	);
}

// ---- Preflight link ----
print '<br>';
print '<div class="center">';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/dmm_preflight_web.php', 1).'">'.img_picto('', 'fa-stethoscope', 'class="pictofixedwidth"').$langs->trans('DMMRunPreflight').'</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
