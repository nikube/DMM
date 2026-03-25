<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/backups.php
 * \ingroup dolimodulemanager
 * \brief   Backup viewer and restore page
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
dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->hasRight('dolimodulemanager', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

$form = new Form($db);
$backupObj = new DMMBackup($db);

/*
 * Actions
 */

// Restore backup
if ($action == 'confirm_restore' && $id > 0 && $user->hasRight('dolimodulemanager', 'write')) {
	$backupObj->fetch($id);
	$result = $backupObj->restore();
	if ($result['success']) {
		setEventMessages($langs->trans('DMMBackupRestored'), null, 'mesgs');
		setEventMessages($langs->trans('DMMReactivateAdvice'), null, 'warnings');

		// Update module registry
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
if ($action == 'confirm_deletebackup' && $id > 0 && $user->hasRight('dolimodulemanager', 'admin')) {
	$backupObj->fetch($id);
	$result = $backupObj->delete($user, true);
	if ($result > 0) {
		setEventMessages($langs->trans('DMMBackupDeleted'), null, 'mesgs');
	} else {
		setEventMessages($backupObj->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Cleanup old backups
if ($action == 'cleanup' && $user->hasRight('dolimodulemanager', 'admin')) {
	$removed = $backupObj->cleanup();
	setEventMessages('Cleaned up '.$removed.' old backups', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMBackupsTab');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-backups');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'backups', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// Action buttons
if ($user->hasRight('dolimodulemanager', 'admin')) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=cleanup&token='.newToken().'">'.$langs->trans('DMMCleanupBackups').'</a>';
	print '</div>';
}

// Backup list
$backups = $backupObj->fetchAll();

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DMMModuleId').'</td>';
print '<td>'.$langs->trans('DMMVersionFrom').'</td>';
print '<td>'.$langs->trans('DMMVersionTo').'</td>';
print '<td>'.$langs->trans('DMMBackupDate').'</td>';
print '<td>'.$langs->trans('DMMBackupSize').'</td>';
print '<td class="center">'.$langs->trans('DMMBackupStatus').'</td>';
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
	print '<td>'.($b->backup_size ? dol_print_size($b->backup_size, 0) : '-').'</td>';

	print '<td class="center">';
	switch ($b->status) {
		case 'ok':
			print '<span class="badge badge-status4">'.$langs->trans('DMMBackupStatusOk').'</span>';
			break;
		case 'restored':
			print '<span class="badge badge-info">'.$langs->trans('DMMBackupStatusRestored').'</span>';
			break;
		default:
			print '<span class="badge badge-secondary">'.dol_escape_htmltag($b->status).'</span>';
	}
	print '</td>';

	print '<td class="center nowraponall">';
	if ($b->status === 'ok' && $user->hasRight('dolimodulemanager', 'write')) {
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=restorebackup&token='.newToken().'&id='.$b->id.'" title="'.$langs->trans('DMMRestore').'">'.img_picto($langs->trans('DMMRestore'), 'fa-undo').'</a>';
	}
	if ($user->hasRight('dolimodulemanager', 'admin')) {
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=deletebackup&token='.newToken().'&id='.$b->id.'" title="'.$langs->trans('DMMDelete').'">'.img_picto($langs->trans('DMMDelete'), 'delete').'</a>';
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
	$msg = $langs->transnoentities('DMMConfirmRestore', $b->module_id, $b->version_from);
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('DMMRestore'),
		$msg,
		'confirm_restore',
		'',
		0,
		1
	);
}

// Delete confirmation
if ($action == 'deletebackup' && $id > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('DMMDelete'),
		$langs->trans('DMMConfirmDeleteBackup'),
		'confirm_deletebackup',
		'',
		0,
		1
	);
}

print dol_get_fiche_end();

llxFooter();
$db->close();
