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
 * \brief   Simple settings: fine-grained GitHub token and DoliStore credentials.
 *          Advanced options live in advanced.php, sources in sources.php.
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

// Canonical label for the single token the simple form manages. The full multi-token
// management still lives on the Sources tab — this is a convenience writer that upserts
// one real DMMToken row (NOT a parallel store) so resolveToken()/discovery see it.
$simpleTokenLabel = 'Default (fine-grained)';

/*
 * Actions
 */

// Save the simple fine-grained token: upsert a single DMMToken row by label.
if ($action == 'savesimpletoken') {
	$tokenValue = GETPOST('simple_token', 'alphanohtml');
	if ($tokenValue === '__keep__' || $tokenValue === '') {
		// Nothing typed — leave the existing token untouched.
		setEventMessages($langs->trans('DMMSettingsSaved'), null, 'mesgs');
	} else {
		$tokenObj = new DMMToken($db);
		$existingId = 0;
		foreach ($tokenObj->fetchAll() as $t) {
			if ($t->label === $simpleTokenLabel) {
				$existingId = $t->id;
				break;
			}
		}
		if ($existingId > 0) {
			$tokenObj->fetch($existingId);
			$tokenObj->token = $tokenValue;
			$tokenObj->token_type = 'fine_grained';
			$result = $tokenObj->update($user);
			if ($result > 0) {
				setEventMessages($langs->trans('DMMTokenSaved'), null, 'mesgs');
			} else {
				setEventMessages($tokenObj->error, null, 'errors');
			}
		} else {
			$tokenObj->label = $simpleTokenLabel;
			$tokenObj->token = $tokenValue;
			$tokenObj->token_type = 'fine_grained';
			$tokenObj->use_for_public = 0;
			$result = $tokenObj->create($user);
			if ($result > 0) {
				setEventMessages($langs->trans('DMMTokenSaved'), null, 'mesgs');
				// Auto-discover modules for the brand-new token (parity with Sources tab).
				dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
				$client = new DMMClient($db);
				$newToken = new DMMToken($db);
				$newToken->fetch($result);
				$discovery = $client->discoverModules($newToken->id, $newToken->getDecryptedToken());
				dmm_show_discovery_report($discovery, $langs);
			} else {
				setEventMessages($tokenObj->error, null, 'errors');
			}
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Save DoliStore credentials (cookie + email/password fallback).
if ($action == 'savesettings') {
	// Encrypt the secrets before persisting; same dolEncrypt pattern as DMMToken. The
	// form renders existing values as the sentinel "__keep__" so we never round-trip
	// cleartext secrets to the browser — recognise that sentinel here.
	// The session cookie lives in the Advanced tab now. It is deliberately NOT read
	// here: this form no longer submits the field, so touching the setting would
	// silently wipe a cookie the user set over there.
	dmm_set_setting('dolistore_email', GETPOST('dolistore_email', 'restricthtml'));
	$pwIn = (string) GETPOST('dolistore_password', 'password');
	if ($pwIn === '__keep__') {
		// keep existing value
	} elseif ($pwIn === '') {
		dmm_set_setting('dolistore_password', '');
	} else {
		dmm_set_setting('dolistore_password', dolEncrypt($pwIn));
	}

	setEventMessages($langs->trans('DMMSettingsSaved'), null, 'mesgs');

	// If the user clicked "Test connection" (which submits the form with run_test=1),
	// run the verification right after persisting so the test uses what they just
	// typed, not stale values.
	if (GETPOST('run_test', 'int')) {
		dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');
		// Wipe any cached cookie jar so a freshly-pasted cookie / new password
		// is exercised end-to-end (not the previously-validated session).
		$jar = (isset($conf->dolimodulemanager->dir_temp) ? $conf->dolimodulemanager->dir_temp : DOL_DATA_ROOT.'/dolimodulemanager/temp').'/dolistore_session.cookies';
		@unlink($jar);

		$ses = new DMMDolistoreSession($db);
		$ok = $ses->verifySession();
		if (!$ok && $ses->errorCode !== 'no_creds' && $ses->errorCode !== 'no_dom') {
			// Cookie missing/dead — try password fallback if configured.
			$ok = $ses->tryLoginWithPassword() && $ses->verifySession();
		}
		if ($ok) {
			setEventMessages($langs->trans('DMMDolistoreTestOk'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('DMMDolistoreTestFailed').' ('.dol_escape_htmltag($ses->error ?: $ses->errorCode).')', null, 'errors');
		}
	}

	header('Location: '.$_SERVER['PHP_SELF'].'#dolistore');
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

$head = dolimodulemanagerAdminPrepareHead('settings');
print dol_get_fiche_head($head, 'settings', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- Simple GitHub token ----
$tokenObjView = new DMMToken($db);
$simpleTokenHasValue = false;
foreach ($tokenObjView->fetchAll() as $t) {
	if ($t->label === $simpleTokenLabel && !empty($t->token)) {
		$simpleTokenHasValue = true;
		break;
	}
}

print '<h3>'.$langs->trans('DMMSimpleTokenTitle').'</h3>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesimpletoken">';
print '<table class="noborder centpercent editmode">';
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMSimpleToken').'</td>';
print '<td><input type="password" name="simple_token" class="minwidth300" autocomplete="new-password" value="'.($simpleTokenHasValue ? '__keep__' : '').'" placeholder="github_pat_...">';
print '<br><span class="opacitymedium small">'.$langs->trans('DMMSimpleTokenHelp').'</span>';
print '</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// ---- DoliStore credentials (for the "My purchases" tab) ----
print '<br>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesettings">';

$dolistoreEmail = dmm_get_setting('dolistore_email', '');
$dolistorePwHasValue = (dmm_get_setting('dolistore_password', '') !== '');

print '<table class="noborder centpercent editmode">';
print '<tr class="liste_titre"><td colspan="2"><a id="dolistore"></a>'.$langs->trans('DMMDolistoreCreds').'</td></tr>';
print '<tr class="oddeven"><td class="titlefieldcreate">'.$langs->trans('DMMDolistoreEmail').'</td>';
print '<td><input type="email" name="dolistore_email" value="'.dol_escape_htmltag($dolistoreEmail).'" class="minwidth300"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DMMDolistorePassword').'</td>';
print '<td><input type="password" name="dolistore_password" value="'.($dolistorePwHasValue ? '__keep__' : '').'" class="minwidth300" autocomplete="new-password">';
// Submit the form with a flag so the save handler runs verifySession() right
// after persisting the new values. Avoids the "test before save" footgun where
// the user types fresh creds and the test was running against stale settings.
print ' <button type="submit" name="run_test" value="1" class="butAction">'.$langs->trans('DMMTestConnection').'</button>';
print '<br><span class="opacitymedium small">'.$langs->trans('DMMDolistorePasswordHelp').'</span>';
print '</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
