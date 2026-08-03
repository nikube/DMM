<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/sources.php
 * \ingroup dolimodulemanager
 * \brief   Module sources management: hubs, GitHub tokens, public repositories
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
			$tokenObj->use_for_public = GETPOST('use_for_public', 'int') ? 1 : 0;
			$tokenObj->note = $note;
			$result = $tokenObj->update($user);
		} else {
			$tokenObj->label = $label;
			$tokenObj->token = $tokenValue;
			$tokenObj->github_owner = $github_owner;
			$tokenObj->token_type = $token_type ?: 'pat';
			$tokenObj->use_for_public = GETPOST('use_for_public', 'int') ? 1 : 0;
			$tokenObj->note = $note;
			$result = $tokenObj->create($user);
		}

		if ($result > 0) {
			setEventMessages($langs->trans('DMMTokenSaved'), null, 'mesgs');

			// Auto-discover modules for new tokens
			if ($action == 'addtoken') {
				dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
				$client = new DMMClient($db);
				$newToken = new DMMToken($db);
				$newToken->fetch($result);
				$discovery = $client->discoverModules($newToken->id, $newToken->getDecryptedToken());
				dmm_show_discovery_report($discovery, $langs);
			}

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

// Discover modules for a token
if ($action == 'discover' && $id > 0) {
	$tokenObj->fetch($id);
	dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
	$client = new DMMClient($db);
	$discovery = $client->discoverModules($tokenObj->id, $tokenObj->getDecryptedToken());
	dmm_show_discovery_report($discovery, $langs);
}

// Toggle use_for_public
if ($action == 'togglepublic' && $id > 0) {
	$tokenObj->fetch($id);
	$tokenObj->use_for_public = $tokenObj->use_for_public ? 0 : 1;
	$tokenObj->update($user);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Toggle token status
if ($action == 'toggletoken' && $id > 0) {
	$tokenObj->fetch($id);
	$tokenObj->status = $tokenObj->status ? 0 : 1;
	$tokenObj->update($user);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Add public repository (no token required)
// The addpublicrepo handler moved to add.php with its form — see "Add a module".

// Add hub URL
if ($action == 'addhub' && dmm_user_can('write')) {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	if (empty($hubUrl) || !preg_match('#^https?://#i', $hubUrl)) {
		setEventMessages($langs->trans('DMMInvalidURL'), null, 'errors');
	} else {
		$hubs = dmm_get_hubs();
		$newId = dmm_hub_identity($hubUrl);
		$exists = false;
		foreach ($hubs as $h) {
			if (dmm_hub_identity($h['url']) === $newId) {
				$exists = true;
				break;
			}
		}
		if ($exists) {
			setEventMessages($langs->trans('DMMHubAlreadyAdded'), null, 'warnings');
		} else {
			dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
			$client = new DMMClient($db);
			$report = $client->importFromHub($hubUrl);
			if (!empty($report['errors'])) {
				setEventMessages(implode(', ', $report['errors']), null, 'errors');
			} else {
				$hubs[] = array('url' => $hubUrl, 'enabled' => 1);
				dmm_save_hubs($hubs);
				dmm_show_hub_report($report);
			}
		}
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// Refresh hub
if ($action == 'refreshhub' && dmm_user_can('write')) {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	if (!empty($hubUrl)) {
		dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
		$client = new DMMClient($db);
		$report = $client->importFromHub($hubUrl);
		dmm_show_hub_report($report);
	}
}

// Inspect hub (show content as toasts)
if ($action == 'inspecthub') {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	if (!empty($hubUrl)) {
		dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
		$client = new DMMClient($db);
		$hub = $client->fetchHub($hubUrl);
		if ($hub) {
			setEventMessages('Hub: '.dol_escape_htmltag($hub['name'] ?? '?'), null, 'mesgs');
			if (!empty($hub['description'])) {
				setEventMessages($hub['description'], null, 'mesgs');
			}
			$pubCount = 0;
			$privCount = 0;
			$moduleNames = array();
			foreach ($hub['modules'] as $entry) {
				$name = $entry['name'] ?? $entry['repo'] ?? '?';
				$vis = !empty($entry['public']) ? 'public' : 'private';
				$moduleNames[] = $name.' ('.$vis.')';
				if (!empty($entry['public'])) {
					$pubCount++;
				} else {
					$privCount++;
				}
			}
			setEventMessages(count($hub['modules']).' modules: '.$pubCount.' public, '.$privCount.' private', null, 'mesgs'); // inspect toast, no lang key needed
			setEventMessages(implode(', ', $moduleNames), null, 'mesgs');
		} else {
			setEventMessages($client->error ?: $langs->trans('DMMFailedFetchHub'), null, 'errors');
		}
	}
}

// Toggle hub enabled/disabled
if ($action == 'togglehub') {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	$toggleId = dmm_hub_identity($hubUrl);
	$hubs = dmm_get_hubs();
	foreach ($hubs as &$h) {
		if (dmm_hub_identity($h['url']) === $toggleId) {
			$h['enabled'] = $h['enabled'] ? 0 : 1;
			break;
		}
	}
	unset($h);
	dmm_save_hubs($hubs);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Remove hub
if ($action == 'removehub' && dmm_user_can('write')) {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	$removeId = dmm_hub_identity($hubUrl);
	$hubs = dmm_get_hubs();
	$hubs = array_values(array_filter($hubs, function ($h) use ($removeId) {
		return dmm_hub_identity($h['url']) !== $removeId;
	}));
	dmm_save_hubs($hubs);
	dmm_set_setting('hub_cache_'.md5($hubUrl), '');
	dmm_set_setting('hub_last_fetch_'.md5($hubUrl), '');
	setEventMessages($langs->trans('DMMHubRemoved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

// Ensure default hub is in the list (for existing installs that didn't have data.sql re-run)
$defaultHubUrl = 'https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json';
$hubs = dmm_get_hubs();
$defaultId = dmm_hub_identity($defaultHubUrl);
$hasDefault = false;
foreach ($hubs as $h) {
	if (dmm_hub_identity($h['url']) === $defaultId) {
		$hasDefault = true;
		break;
	}
}
if (!$hasDefault) {
	$hubs[] = array('url' => $defaultHubUrl, 'enabled' => 1);
	dmm_save_hubs($hubs);
}

$title = $langs->trans('DMMSourcesTab');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-sources');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead('sources');
print dol_get_fiche_head($head, 'sources', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- Module Hubs (top) ----
print '<h3>'.$langs->trans('DMMModuleHubs').'</h3>';

$hubs = dmm_get_hubs();

if (!empty($hubs)) {
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Name').'</td>';
	print '<td class="tdoverflowmax250">URL</td>';
	print '<td class="center">'.$langs->trans('Status').'</td>';
	print '<td class="center">'.$langs->trans('DMMLastCheck').'</td>';
	print '<td class="center">'.$langs->trans('DMMModulesManaged').'</td>';
	print '<td class="center">'.$langs->trans('Action').'</td>';
	print '</tr>';

	foreach ($hubs as $hub) {
		$hUrl = $hub['url'];
		$cacheKey = md5($hUrl);
		$hubCache = json_decode(dmm_get_setting('hub_cache_'.$cacheKey, '{}'), true);
		$hubLastFetch = dmm_get_setting('hub_last_fetch_'.$cacheKey, '');
		$hubName = $hubCache['name'] ?? '-';
		$hubTotal = $hubCache['total'] ?? '?';
		$hubError = $hubCache['error'] ?? '';

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($hubName);
		if (!empty($hubError)) {
			print ' <span class="badge badge-warning">'.$langs->trans('DMMPrivate').'</span>';
		}
		print '</td>';
		print '<td class="tdoverflowmax250 small">'.dol_escape_htmltag($hUrl).'</td>';
		print '<td class="center">';
		if ($hub['enabled']) {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglehub&token='.newToken().'&hub_url='.urlencode($hUrl).'">'.img_picto($langs->trans('Enabled'), 'switch_on').'</a>';
		} else {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglehub&token='.newToken().'&hub_url='.urlencode($hUrl).'">'.img_picto($langs->trans('Disabled'), 'switch_off').'</a>';
		}
		print '</td>';
		print '<td class="center">'.(!empty($hubLastFetch) ? dol_escape_htmltag($hubLastFetch) : '-').'</td>';
		print '<td class="center">';
		if (!empty($hubError)) {
			print '<span class="opacitymedium" title="'.dol_escape_htmltag($hubError).'">'.img_picto($hubError, 'fa-lock').'</span>';
		} else {
			print $hubTotal;
		}
		print '</td>';
		print '<td class="center nowraponall">';
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=inspecthub&token='.newToken().'&hub_url='.urlencode($hUrl).'" title="'.$langs->trans('DMMInspectHub').'">'.img_picto($langs->trans('DMMInspectHub'), 'fa-search').'</a>';
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=refreshhub&token='.newToken().'&hub_url='.urlencode($hUrl).'" title="'.$langs->trans('DMMRefreshHub').'">'.img_picto($langs->trans('DMMRefreshHub'), 'fa-sync').'</a>';
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=removehub&token='.newToken().'&hub_url='.urlencode($hUrl).'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>';
		print '</td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
}

// Add hub form
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addhub">';
print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMAddHub').'</td></tr>';
print '<tr class="oddeven"><td class="fieldrequired titlefieldcreate">'.$langs->trans('DMMHubURL').'</td>';
print '<td><input type="text" name="hub_url" class="minwidth400 maxwidth600" placeholder="https://raw.githubusercontent.com/org/hub/main/dmmhub.json" value="'.dol_escape_htmltag(GETPOST('hub_url')).'"></td></tr>';
print '<tr class="oddeven"><td colspan="2" class="opacitymedium small">'.$langs->trans('DMMAddHubHelp').'</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Add').'"></div>';
print '</form>';

// ---- Token list ----
print '<br>';
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
print '<td class="center">'.$form->textwithpicto($langs->trans('DMMUseForPublic'), $langs->trans('DMMUseForPublicTooltip')).'</td>';
print '<td class="center">'.$langs->trans('DMMTokenLastValidated').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($allTokens)) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
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
	print '<td class="center">';
	if ($t->use_for_public) {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglepublic&token='.newToken().'&id='.$t->id.'">'.img_picto($langs->trans('Yes'), 'switch_on').'</a>';
	} else {
		print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglepublic&token='.newToken().'&id='.$t->id.'">'.img_picto($langs->trans('No'), 'switch_off').'</a>';
	}
	print '</td>';
	print '<td class="center">'.($t->last_validated ? dol_print_date($t->last_validated, 'dayhour') : '-').'</td>';
	print '<td class="center nowraponall">';
	// Discover button
	print '<a class="reposition paddingright" href="'.$_SERVER['PHP_SELF'].'?action=discover&token='.newToken().'&id='.$t->id.'" title="'.$langs->trans('DMMDiscover').'">'.img_picto($langs->trans('DMMDiscover'), 'fa-search').'</a>';
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

// ---- Add Token / Add Public Repo — side by side ----
$editMode = ($action == 'edittoken' && $id > 0);
$editToken = null;
if ($editMode) {
	$editToken = new DMMToken($db);
	$editToken->fetch($id);
}

print '<br>';
print '<div class="fichecenter"><div class="fichehalfleft">';

// -- Left: Add/Edit token --
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($editMode ? 'updatetoken' : 'addtoken').'">';
if ($editMode) {
	print '<input type="hidden" name="id" value="'.$editToken->id.'">';
}

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.($editMode ? $langs->trans('DMMEditToken') : $langs->trans('DMMAddToken')).'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired titlefieldcreate">'.$langs->trans('DMMTokenLabel').'</td>';
print '<td><input type="text" name="token_label" class="maxwidth200" value="'.dol_escape_htmltag($editMode ? $editToken->label : GETPOST('token_label')).'"></td></tr>';

print '<tr class="oddeven"><td class="'.($editMode ? '' : 'fieldrequired').'">'.$langs->trans('DMMTokenValue').'</td>';
print '<td><input type="password" name="token_value" class="maxwidth250" autocomplete="off" placeholder="'.($editMode ? $editToken->getMaskedToken() : 'ghp_...').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenOwner').'</td>';
print '<td><input type="text" name="token_owner" class="maxwidth200" value="'.dol_escape_htmltag($editMode ? $editToken->github_owner : GETPOST('token_owner')).'" placeholder="owner-or-org"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenType').'</td>';
print '<td><select name="token_type" class="maxwidth200">';
$selectedType = $editMode ? $editToken->token_type : GETPOST('token_type');
print '<option value="pat"'.($selectedType !== 'fine_grained' ? ' selected' : '').'>'.$langs->trans('DMMTokenTypePAT').'</option>';
print '<option value="fine_grained"'.($selectedType === 'fine_grained' ? ' selected' : '').'>'.$langs->trans('DMMTokenTypeFineGrained').'</option>';
print '</select></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans('DMMUseForPublic'), $langs->trans('DMMUseForPublicTooltip')).'</td>';
$ufpChecked = $editMode ? $editToken->use_for_public : GETPOST('use_for_public', 'int');
print '<td><input type="checkbox" name="use_for_public" value="1"'.($ufpChecked ? ' checked' : '').'></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DMMTokenNote').'</td>';
print '<td><textarea name="token_note" rows="2" class="maxwidth250">'.dol_escape_htmltag($editMode ? $editToken->note : GETPOST('token_note')).'</textarea></td></tr>';

print '</table>';
print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
if ($editMode) {
	print ' <a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Cancel').'</a>';
}
print '</div>';
print '</form>';

print '</div><div class="fichehalfright">';

// -- Right: adding a single module now lives on the "Add a module" tab. This tab
// configures where modules come from (tokens, hubs); adding one is an action, not
// a setting, and it belongs with the other four ways in.
print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td>'.$langs->trans('DMMAddPublicRepo').'</td></tr>';
print '<tr class="oddeven"><td class="opacitymedium">'.$langs->trans('DMMAddRepoMovedToAdd').'</td></tr>';
print '</table>';
print '<div class="center paddingtop"><a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/add.php', 1).'#repo">'.$langs->trans('DMMAddModule').'</a></div>';

print '</div></div>';
print '<div class="clearboth"></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
