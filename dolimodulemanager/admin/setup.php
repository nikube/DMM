<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup dolimodulemanager
 * \brief   Token management and settings page
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
dol_include_once('/dolimodulemanager/class/DMMToken.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');

$tokenObj = new DMMToken($db);

/*
 * Actions
 */

// Add or update token
if ($action == 'addtoken' || $action == 'updatetoken') {
	$label = GETPOST('token_label', 'alphanohtml');
	$tokenValue = GETPOST('token_value', 'alphanohtml');
	$github_owner = GETPOST('token_owner', 'alphanohtml');
	$token_type = GETPOST('token_type', 'alphanohtml');
	$note = GETPOST('token_note', 'restricthtml');

	if (empty($label)) {
		setEventMessages($langs->trans('DMMErrorTokenRequired'), null, 'errors');
	} elseif ($action == 'addtoken' && empty($tokenValue)) {
		setEventMessages($langs->trans('DMMErrorTokenRequired'), null, 'errors');
	} else {
		if ($action == 'updatetoken' && $id > 0) {
			$tokenObj->fetch($id);
			$tokenObj->label = $label;
			if (!empty($tokenValue)) {
				$tokenObj->token = $tokenValue;
			}
			$tokenObj->github_owner = $github_owner;
			$tokenObj->token_type = $token_type ?: 'pat';
			$tokenObj->note = $note;
			$result = $tokenObj->update($user);
		} else {
			$tokenObj->label = $label;
			$tokenObj->token = $tokenValue;
			$tokenObj->github_owner = $github_owner;
			$tokenObj->token_type = $token_type ?: 'pat';
			$tokenObj->note = $note;
			$result = $tokenObj->create($user);
		}

		if ($result > 0) {
			setEventMessages($langs->trans('DMMTokenSaved'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		} else {
			setEventMessages($tokenObj->error, null, 'errors');
		}
	}
}

// Delete token
if ($action == 'confirm_deletetoken' && $id > 0) {
	$tokenObj->fetch($id);
	$result = $tokenObj->delete($user);
	if ($result > 0) {
		setEventMessages($langs->trans('DMMTokenDeleted'), null, 'mesgs');
	} else {
		setEventMessages($tokenObj->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Test token
if ($action == 'testtoken' && $id > 0) {
	$tokenObj->fetch($id);
	$valid = $tokenObj->validate();
	if ($valid) {
		setEventMessages($langs->trans('DMMTokenValid'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('DMMTokenInvalid'), null, 'errors');
	}
}

// Toggle token status
if ($action == 'toggletoken' && $id > 0) {
	$tokenObj->fetch($id);
	$tokenObj->status = $tokenObj->status ? 0 : 1;
	$tokenObj->update($user);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Save settings
if ($action == 'savesettings') {
	dmm_set_setting('check_interval', GETPOST('check_interval', 'int'));
	dmm_set_setting('backup_retention_days', GETPOST('backup_retention_days', 'int'));
	dmm_set_setting('backup_retention_count', GETPOST('backup_retention_count', 'int'));
	dmm_set_setting('notify_email', GETPOST('notify_email', 'alphanohtml'));
	dmm_set_setting('temp_dir', GETPOST('temp_dir', 'alphanohtml'));
	setEventMessages($langs->trans('DMMSettingsSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMSettingsTab');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- Token list ----
print '<h3>'.$langs->trans('DMMTokens').'</h3>';

$allTokens = $tokenObj->fetchAll();

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DMMTokenLabel').'</td>';
print '<td>'.$langs->trans('DMMTokenMasked').'</td>';
print '<td>'.$langs->trans('DMMTokenOwner').'</td>';
print '<td>'.$langs->trans('DMMTokenType').'</td>';
print '<td class="center">'.$langs->trans('DMMTokenStatus').'</td>';
print '<td class="center">'.$langs->trans('DMMTokenLastValidated').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($allTokens)) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}

foreach ($allTokens as $t) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($t->label).'</td>';
	print '<td><code>'.dol_escape_htmltag($t->getMaskedToken()).'</code></td>';
	print '<td>'.dol_escape_htmltag($t->github_owner).'</td>';
	print '<td>'.($t->token_type === 'fine_grained' ? $langs->trans('DMMTokenTypeFineGrained') : $langs->trans('DMMTokenTypePAT')).'</td>';
	print '<td class="center">';
	if ($t->status) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=toggletoken&token='.newToken().'&id='.$t->id.'">'.img_picto($langs->trans('DMMTokenActive'), 'switch_on').'</a>';
	} else {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=toggletoken&token='.newToken().'&id='.$t->id.'">'.img_picto($langs->trans('DMMTokenDisabled'), 'switch_off').'</a>';
	}
	print '</td>';
	print '<td class="center">'.($t->last_validated ? dol_print_date($t->last_validated, 'dayhour') : '-').'</td>';
	print '<td class="center nowraponall">';
	// Test button
	print '<a class="reposition paddingright" href="'.$_SERVER['PHP_SELF'].'?action=testtoken&token='.newToken().'&id='.$t->id.'" title="'.$langs->trans('DMMTestToken').'">'.img_picto($langs->trans('DMMTestToken'), 'fa-check-circle').'</a>';
	// Edit button
	print '<a class="editfielda paddingright" href="'.$_SERVER['PHP_SELF'].'?action=edittoken&token='.newToken().'&id='.$t->id.'" title="'.$langs->trans('Modify').'">'.img_picto($langs->trans('Modify'), 'edit').'</a>';
	// Delete button
	print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=deletetoken&token='.newToken().'&id='.$t->id.'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>';
	print '</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Delete confirmation
if ($action == 'deletetoken' && $id > 0) {
	$tokenObj->fetch($id);
	$formconfirm = $form ?? new Form($db);
	print $formconfirm->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id,
		$langs->trans('DMMDeleteToken'),
		$langs->trans('DMMConfirmDeleteToken'),
		'confirm_deletetoken',
		'',
		0,
		1
	);
}

// ---- Add/Edit token form ----
$editMode = ($action == 'edittoken' && $id > 0);
$editToken = null;
if ($editMode) {
	$editToken = new DMMToken($db);
	$editToken->fetch($id);
}

print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($editMode ? 'updatetoken' : 'addtoken').'">';
if ($editMode) {
	print '<input type="hidden" name="id" value="'.$editToken->id.'">';
}

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.($editMode ? $langs->trans('DMMEditToken') : $langs->trans('DMMAddToken')).'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired titlefieldcreate">'.$langs->trans('DMMTokenLabel').'</td>';
print '<td><input type="text" name="token_label" class="minwidth300" value="'.dol_escape_htmltag($editMode ? $editToken->label : GETPOST('token_label')).'"></td></tr>';

print '<tr class="oddeven"><td class="'.($editMode ? '' : 'fieldrequired').'">'.$langs->trans('DMMTokenValue').'</td>';
print '<td><input type="password" name="token_value" class="minwidth400" autocomplete="off" placeholder="'.($editMode ? $langs->trans('DMMTokenMasked').' - '.$editToken->getMaskedToken() : 'ghp_...').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenOwner').'</td>';
print '<td><input type="text" name="token_owner" class="minwidth200" value="'.dol_escape_htmltag($editMode ? $editToken->github_owner : GETPOST('token_owner')).'" placeholder="owner-or-org"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenType').'</td>';
print '<td><select name="token_type" class="minwidth200">';
$selectedType = $editMode ? $editToken->token_type : GETPOST('token_type');
print '<option value="pat"'.($selectedType !== 'fine_grained' ? ' selected' : '').'>'.$langs->trans('DMMTokenTypePAT').'</option>';
print '<option value="fine_grained"'.($selectedType === 'fine_grained' ? ' selected' : '').'>'.$langs->trans('DMMTokenTypeFineGrained').'</option>';
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenNote').'</td>';
print '<td><textarea name="token_note" rows="2" class="minwidth400">'.dol_escape_htmltag($editMode ? $editToken->note : GETPOST('token_note')).'</textarea></td></tr>';

print '</table>';
print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
if ($editMode) {
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
}
print '</div>';
print '</form>';

// ---- General settings ----
print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesettings">';

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMGeneralSettings').'</td></tr>';

$checkInterval = dmm_get_setting('check_interval', '86400');
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMCheckInterval').'</td>';
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

print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
