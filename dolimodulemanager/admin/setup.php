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
if ($action == 'addpublicrepo' && $user->hasRight('dolimodulemanager', 'write')) {
	$repo = GETPOST('public_repo', 'alphanohtml');

	if (empty($repo) || strpos($repo, '/') === false) {
		setEventMessages($langs->trans('DMMErrorRepoFormat'), null, 'errors');
	} else {
		dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
		dol_include_once('/dolimodulemanager/class/DMMClient.class.php');

		$client = new DMMClient($db);
		list($owner, $repoName) = explode('/', $repo, 2);

		// Fetch manifest without token (public repo)
		$manifest = $client->fetchManifest($owner, $repoName, null);

		$module_id = $manifest['module_id'] ?? strtolower(preg_replace('/[^a-z0-9_]/i', '', $repoName));

		// Check if already registered
		$existing = new DMMModule($db);
		if ($existing->fetch(0, $module_id) > 0) {
			setEventMessages('Module '.$module_id.' already registered', null, 'warnings');
		} else {
			$mod = new DMMModule($db);
			$mod->module_id = $module_id;
			$mod->github_repo = $repo;
			$mod->fk_dmm_token = null;
			$mod->name = $manifest['name'] ?? null;
			$mod->description = $manifest['description'] ?? null;
			$mod->author = $manifest['author'] ?? null;
			$mod->license = $manifest['license'] ?? null;
			$mod->url = $manifest['url'] ?? null;

			// Auto-detect if installed
			$localDir = DOL_DOCUMENT_ROOT.'/custom/'.$module_id;
			if (is_dir($localDir) && is_dir($localDir.'/core/modules')) {
				$mod->installed = 1;
				$descFiles = glob($localDir.'/core/modules/mod*.class.php');
				if (!empty($descFiles)) {
					$content = file_get_contents($descFiles[0]);
					if (preg_match('/\$this->version\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $vm)) {
						$mod->installed_version = $vm[1];
					}
				}
			}

			$result = $mod->create($user);
			if ($result > 0) {
				setEventMessages('Public repository added: '.$repo, null, 'mesgs');
			} else {
				setEventMessages($mod->error, null, 'errors');
			}
		}
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// Add hub URL
if ($action == 'addhub' && $user->hasRight('dolimodulemanager', 'write')) {
	$hubUrl = trim(GETPOST('hub_url', 'alphanohtml'));
	if (empty($hubUrl) || !preg_match('#^https?://#i', $hubUrl)) {
		setEventMessages('Invalid URL: must start with https://', null, 'errors');
	} else {
		$hubs = dmm_get_hubs();
		$exists = false;
		foreach ($hubs as $h) {
			if ($h['url'] === $hubUrl) {
				$exists = true;
				break;
			}
		}
		if ($exists) {
			setEventMessages('Hub already added', null, 'warnings');
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
if ($action == 'refreshhub' && $user->hasRight('dolimodulemanager', 'write')) {
	$hubUrl = GETPOST('hub_url', 'alphanohtml');
	if (!empty($hubUrl)) {
		dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
		$client = new DMMClient($db);
		$report = $client->importFromHub($hubUrl);
		dmm_show_hub_report($report);
	}
}

// Inspect hub (show content as toasts)
if ($action == 'inspecthub') {
	$hubUrl = GETPOST('hub_url', 'alphanohtml');
	if (!empty($hubUrl)) {
		dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
		$client = new DMMClient($db);
		$hub = $client->fetchHub($hubUrl);
		if ($hub) {
			setEventMessages('Hub: '.($hub['name'] ?? '?'), null, 'mesgs');
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
			setEventMessages(count($hub['modules']).' modules: '.$pubCount.' public, '.$privCount.' private', null, 'mesgs');
			setEventMessages(implode(', ', $moduleNames), null, 'mesgs');
		} else {
			setEventMessages($client->error ?: 'Failed to fetch hub', null, 'errors');
		}
	}
}

// Toggle hub enabled/disabled
if ($action == 'togglehub') {
	$hubUrl = GETPOST('hub_url', 'alphanohtml');
	$hubs = dmm_get_hubs();
	foreach ($hubs as &$h) {
		if ($h['url'] === $hubUrl) {
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
if ($action == 'removehub' && $user->hasRight('dolimodulemanager', 'write')) {
	$hubUrl = GETPOST('hub_url', 'alphanohtml');
	$hubs = dmm_get_hubs();
	$hubs = array_values(array_filter($hubs, function ($h) use ($hubUrl) {
		return $h['url'] !== $hubUrl;
	}));
	dmm_save_hubs($hubs);
	dmm_set_setting('hub_cache_'.md5($hubUrl), '');
	dmm_set_setting('hub_last_fetch_'.md5($hubUrl), '');
	setEventMessages('Hub removed', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Save settings
if ($action == 'savesettings') {
	dmm_set_setting('auto_check', GETPOST('auto_check', 'int') ? '1' : '0');
	dmm_set_setting('auto_migrate', GETPOST('auto_migrate', 'int') ? '1' : '0');
	dmm_set_setting('check_interval', GETPOST('check_interval', 'int'));
	dmm_set_setting('backup_retention_days', GETPOST('backup_retention_days', 'int'));
	dmm_set_setting('backup_retention_count', GETPOST('backup_retention_count', 'int'));
	dmm_set_setting('notify_email', GETPOST('notify_email', 'alphanohtml'));
	dmm_set_setting('temp_dir', GETPOST('temp_dir', 'alphanohtml'));
	setEventMessages($langs->trans('DMMSettingsSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Restore backup
if ($action == 'confirm_restore' && $id > 0 && $user->hasRight('dolimodulemanager', 'write')) {
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
if ($action == 'confirm_deletebackup' && $id > 0 && $user->hasRight('dolimodulemanager', 'admin')) {
	dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
	$backupObj = new DMMBackup($db);
	$backupObj->fetch($id);
	$backupObj->delete($user, true);
	setEventMessages($langs->trans('DMMBackupDeleted'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Cleanup old backups
if ($action == 'cleanup' && $user->hasRight('dolimodulemanager', 'admin')) {
	dol_include_once('/dolimodulemanager/class/DMMBackup.class.php');
	$backupObj = new DMMBackup($db);
	$removed = $backupObj->cleanup();
	setEventMessages('Cleaned up '.$removed.' old backups', null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

// Ensure default hub is in the list (for existing installs that didn't have data.sql re-run)
$defaultHubUrl = 'https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json';
$hubs = dmm_get_hubs();
$hasDefault = false;
foreach ($hubs as $h) {
	if ($h['url'] === $defaultHubUrl) {
		$hasDefault = true;
		break;
	}
}
if (!$hasDefault) {
	$hubs[] = array('url' => $defaultHubUrl, 'enabled' => 1);
	dmm_save_hubs($hubs);
}

$title = $langs->trans('DMMSettingsTab');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager').' - '.$title, $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

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

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($hubName).'</td>';
		print '<td class="tdoverflowmax250 small">'.dol_escape_htmltag($hUrl).'</td>';
		print '<td class="center">';
		if ($hub['enabled']) {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglehub&token='.newToken().'&hub_url='.urlencode($hUrl).'">'.img_picto($langs->trans('Enabled'), 'switch_on').'</a>';
		} else {
			print '<a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=togglehub&token='.newToken().'&hub_url='.urlencode($hUrl).'">'.img_picto($langs->trans('Disabled'), 'switch_off').'</a>';
		}
		print '</td>';
		print '<td class="center">'.(!empty($hubLastFetch) ? dol_escape_htmltag($hubLastFetch) : '-').'</td>';
		print '<td class="center">'.$hubTotal.'</td>';
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
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
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
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
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

// -- Right: Add public repo --
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addpublicrepo">';

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMAddPublicRepo').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired titlefieldcreate">'.$langs->trans('DMMGitHubRepo').'</td>';
print '<td><input type="text" name="public_repo" class="maxwidth250" placeholder="owner/repository" value="'.dol_escape_htmltag(GETPOST('public_repo')).'"></td></tr>';

print '<tr class="oddeven"><td colspan="2" class="opacitymedium small">'.$langs->trans('DMMAddPublicRepoHelp').'</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Add').'"></div>';
print '</form>';

print '</div></div>';
print '<div class="clearboth"></div>';

// ---- General settings ----
print '<br>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesettings">';

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('DMMGeneralSettings').'</td></tr>';

$autoCheck = dmm_get_setting('auto_check', '1');
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMAutoCheck').'</td>';
print '<td><input type="checkbox" name="auto_check" value="1"'.($autoCheck === '1' ? ' checked' : '').'> '.$langs->trans('DMMAutoCheckHelp').'</td></tr>';

$autoMigrate = dmm_get_setting('auto_migrate', '0');
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

print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

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

if ($user->hasRight('dolimodulemanager', 'admin') && !empty($backups)) {
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
	if ($b->status === 'ok' && $user->hasRight('dolimodulemanager', 'write')) {
		print '<a class="paddingright" href="'.$_SERVER['PHP_SELF'].'?action=restorebackup&token='.newToken().'&id='.$b->id.'" title="'.$langs->trans('DMMRestore').'">'.img_picto($langs->trans('DMMRestore'), 'fa-undo').'</a>';
	}
	if ($user->hasRight('dolimodulemanager', 'admin')) {
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
