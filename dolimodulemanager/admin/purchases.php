<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/purchases.php
 * \ingroup dolimodulemanager
 * \brief   "My DoliStore purchases" tab. Lists modules the user has bought
 *          on www.dolistore.com (scraped from order-history.php) and lets
 *          them install/update with one click. Degrades gracefully when
 *          credentials are missing/expired or the network is down.
 */

// Load Dolibarr environment (boilerplate identical to other DMM admin pages)
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
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->hasRight('dolimodulemanager', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$dolistoreId = GETPOSTINT('dolistore_id');
$wrapperHash = GETPOST('wh', 'alphanohtml'); // index into the cached purchase list

// ---- Cache helpers ----
$baseTemp = isset($conf->dolimodulemanager->dir_temp)
	? $conf->dolimodulemanager->dir_temp
	: DOL_DATA_ROOT.'/dolimodulemanager/temp';
if (!is_dir($baseTemp)) {
	@dol_mkdir($baseTemp);
}
$cacheFile = $baseTemp.'/dolistore_purchases.json';
$cacheTtl = 3600; // 1h

/**
 * Load purchases from the network (with auth) and persist a small cache file
 * so the page stays snappy on refresh. Returns the array shape from
 * DMMDolistoreSession::fetchPurchases() augmented with cache metadata.
 */
function dmm_purchases_load($db, $cacheFile, $cacheTtl, $forceRefresh = false)
{
	if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
		$cached = @json_decode(file_get_contents($cacheFile), true);
		if (is_array($cached) && isset($cached['products'])) {
			$cached['from_cache'] = true;
			return $cached;
		}
	}
	$ses = new DMMDolistoreSession($db);
	$result = $ses->fetchPurchases();
	$result['error_code'] = $ses->errorCode;
	$result['error'] = $ses->error;
	$result['from_cache'] = false;
	if (!empty($result['ok'])) {
		@file_put_contents($cacheFile, json_encode(array(
			'ok' => true,
			'products' => $result['products'],
			'fetched_at' => time(),
		)));
	}
	return $result;
}

// ---- Actions ----

if ($action == 'refresh' && $user->hasRight('dolimodulemanager', 'read')) {
	@unlink($cacheFile);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'install' && $user->hasRight('dolimodulemanager', 'write') && $dolistoreId > 0 && $wrapperHash !== '') {
	// We never put the raw wrapper URL in the form (it's long and contains a
	// per-order key). Instead the listing rendered above stores each URL in
	// the cache file and the form submits an md5() of that URL — we resolve
	// it back here.
	$cached = file_exists($cacheFile) ? @json_decode(file_get_contents($cacheFile), true) : null;
	$wrapperUrl = null;
	$seedName = '';
	if (is_array($cached) && !empty($cached['products'])) {
		foreach ($cached['products'] as $p) {
			if (empty($p['zip_url'])) {
				continue;
			}
			if ((int) ($p['id'] ?? 0) === $dolistoreId && md5($p['zip_url']) === $wrapperHash) {
				$wrapperUrl = $p['zip_url'];
				$seedName = $p['name'] ?? '';
				break;
			}
		}
	}
	if ($wrapperUrl === null) {
		setEventMessages($langs->trans('DMMPurchasesWrapperExpired'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// Seed module_id from the product name (descriptor will refine it post-extract).
	$moduleIdSeed = $seedName !== '' ? $seedName : ('dolistore'.$dolistoreId);
	$moduleId = strtolower(preg_replace('/[^a-z0-9_]/i', '', $moduleIdSeed));
	if ($moduleId === '') {
		$moduleId = 'dolistore'.$dolistoreId;
	}

	// Ensure a registry row exists with source=dolistore + dolistore_id (so
	// the dashboard, update checks and module page recognise it).
	$existing = new DMMModule($db);
	$alreadyRegistered = ($existing->fetch(0, $moduleId) > 0);
	if (!$alreadyRegistered) {
		$sqlCheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $dolistoreId);
		$resCheck = $db->query($sqlCheck);
		if ($resCheck && $db->num_rows($resCheck) > 0) {
			$o = $db->fetch_object($resCheck);
			$alreadyRegistered = ($existing->fetch((int) $o->rowid) > 0);
			$moduleId = $existing->module_id;
		}
	}
	if (!$alreadyRegistered) {
		$mod = new DMMModule($db);
		$mod->module_id = $moduleId;
		$mod->github_repo = 'dolistore:'.$dolistoreId;
		$mod->source = 'dolistore';
		$mod->name = $seedName;
		$mod->url = DMMDolistoreSession::SHOP_URL.'/product.php?id='.$dolistoreId;
		$mod->dolistore_id = $dolistoreId;
		$created = $mod->create($user);
		if ($created < 0) {
			setEventMessages($mod->error ?: 'create failed', null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
	}

	// Run the install pipeline.
	$dmmClient = new DMMClient($db);
	$result = $dmmClient->installFromDolistorePurchase($moduleId, $dolistoreId, $wrapperUrl);

	if (!empty($result['success'])) {
		// Re-resolve canonical row (the descriptor may have renamed module_id).
		$canonical = new DMMModule($db);
		$sqlR = "SELECT rowid FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $dolistoreId);
		$resR = $db->query($sqlR);
		$mod = null;
		if ($resR && $db->num_rows($resR) > 0) {
			$o = $db->fetch_object($resR);
			$canonical->fetch((int) $o->rowid);
			$mod = $canonical;
		}

		$installedVersion = $mod ? ($mod->installed_version ?: '?') : '?';
		setEventMessages($langs->trans('DMMInstallSuccess', $mod ? $mod->module_id : $moduleId, $installedVersion), null, 'mesgs');

		// Same auto-migrate path as the GitHub/free pipeline.
		if ($mod) {
			$autoMigrate = dmm_get_setting('auto_migrate', '1');
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
		}
	} else {
		setEventMessages($result['message'] ?? 'install failed', null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMPurchases');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-purchases');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager'), $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'purchases', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

print '<p class="opacitymedium">'.$langs->trans('DMMPurchasesIntro').'</p>';

// Probe credentials before hitting the network.
$ses = new DMMDolistoreSession($db);
$missing = $ses->checkExtensions();
if ($missing !== null) {
	print info_admin($langs->trans('DMMDolistoreMissingExt', $missing), 0, 0, 'warning');
	llxFooter();
	exit;
}
if (!$ses->hasCredentials()) {
	print info_admin($langs->trans('DMMConfigureDolistoreCreds'), 0, 0, '');
	print '<div class="paddingtop"><a href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'#dolistore" class="butAction">'.$langs->trans('DMMOpenSetup').'</a></div>';
	llxFooter();
	exit;
}

// Refresh button + cache info
print '<div class="paddingbottom">';
print '<a href="'.$_SERVER['PHP_SELF'].'?action=refresh&token='.newToken().'" class="butAction">'.img_picto('', 'refresh', 'class="paddingright"').$langs->trans('DMMRefreshPurchases').'</a>';
print '</div>';

$payload = dmm_purchases_load($db, $cacheFile, $cacheTtl);

if (empty($payload['ok'])) {
	$code = $payload['error_code'] ?? '';
	switch ($code) {
		case 'login_failed':
			print info_admin($langs->trans('DMMDolistoreSessionInvalid'), 0, 0, 'warning');
			break;
		case 'network':
			print info_admin($langs->trans('DMMDolistoreNetworkError'), 0, 0, 'warning');
			break;
		case 'no_creds':
			print info_admin($langs->trans('DMMConfigureDolistoreCreds'), 0, 0, '');
			break;
		default:
			print info_admin($langs->trans('DMMDolistoreUnknownError'), 0, 0, 'warning');
	}
	if (!empty($payload['error'])) {
		print '<details class="opacitymedium small"><summary>'.$langs->trans('DMMTechnicalDetails').'</summary><pre>'.dol_escape_htmltag($payload['error']).'</pre></details>';
	}
	print '<div class="paddingtop"><a href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'#dolistore" class="butAction">'.$langs->trans('DMMOpenSetup').'</a></div>';
	llxFooter();
	exit;
}

$products = $payload['products'] ?? array();
if (!empty($payload['from_cache'])) {
	print '<div class="opacitymedium small paddingbottom">'.$langs->trans('DMMPurchasesCachedNote').'</div>';
}

if (empty($products)) {
	print info_admin($langs->trans('DMMNoPurchasesFound'), 0, 0, '');
	llxFooter();
	exit;
}

// Build "already installed" map (keyed by dolistore_id).
$installedMap = array();
$sql = "SELECT dolistore_id, installed, installed_version FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id IS NOT NULL";
$resSql = $db->query($sql);
if ($resSql) {
	while ($o = $db->fetch_object($resSql)) {
		$installedMap[(int) $o->dolistore_id] = array(
			'installed' => (int) $o->installed,
			'version' => $o->installed_version,
		);
	}
}

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Module').'</th>';
print '<th class="center width150">'.$langs->trans('Order').'</th>';
print '<th class="center width150">'.$langs->trans('DMMDownloadExpires').'</th>';
print '<th class="center width120">'.$langs->trans('Status').'</th>';
print '<th class="center width200">'.$langs->trans('Action').'</th>';
print '</tr>';

foreach ($products as $p) {
	$pid = (int) ($p['id'] ?? 0);
	$installedRow = isset($installedMap[$pid]) ? $installedMap[$pid] : null;
	$isInstalled = $installedRow && $installedRow['installed'];

	print '<tr class="oddeven">';

	// Name + ref
	print '<td>';
	print '<strong>'.dolPrintHTML($p['name']).'</strong>';
	if (!empty($p['ref'])) {
		print '<br><small class="opacitymedium">'.$langs->trans('Ref').': '.dol_escape_htmltag($p['ref']).'</small>';
	}
	if ($pid > 0) {
		print ' <a href="'.DMMDolistoreSession::SHOP_URL.'/product.php?id='.$pid.'" target="_blank" rel="noopener noreferrer" class="opacitymedium small">'.img_picto('', 'url').'</a>';
	}
	print '</td>';

	// Order ref + installed version (if any)
	print '<td class="center">';
	print '<small>'.dol_escape_htmltag($p['ref'] ?? '').'</small>';
	if ($installedRow && !empty($installedRow['version'])) {
		print '<br><small class="opacitymedium">'.$langs->trans('Installed').': '.dol_escape_htmltag($installedRow['version']).'</small>';
	}
	print '</td>';

	// Download expiry
	print '<td class="center"><small class="opacitymedium">'.dol_escape_htmltag($p['expires'] ?? '').'</small></td>';

	// DMM status
	print '<td class="center">';
	if ($isInstalled) {
		print '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>';
	} else {
		print '<span class="opacitymedium small">'.$langs->trans('NotInstalled').'</span>';
	}
	print '</td>';

	// Action
	print '<td class="center">';
	if (!empty($p['zip_url']) && $pid > 0) {
		$wh = md5($p['zip_url']);
		$label = $isInstalled ? $langs->trans('Update') : $langs->trans('Install');
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=install&dolistore_id='.$pid.'&wh='.$wh.'&token='.newToken().'" class="butAction">'.img_picto('', 'download', 'class="paddingright"').' '.$label.'</a>';
	} else {
		print '<span class="opacitymedium small">'.$langs->trans('DMMNoDownloadAvailable').'</span>';
	}
	print '</td>';

	print '</tr>';
}

print '</table>';
print '</div>';

llxFooter();
$db->close();
