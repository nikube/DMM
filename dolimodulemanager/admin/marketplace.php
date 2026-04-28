<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/marketplace.php
 * \ingroup dolimodulemanager
 * \brief   Aggregated DoliStore + community marketplace, with one-click
 *          add/install routed through DMM instead of leaving the page.
 */

// Load Dolibarr environment (boilerplate identical to the other DMM admin pages)
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
dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

if (!$user->hasRight('dolimodulemanager', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$dolistoreId = GETPOSTINT('dolistore_id');
$searchKw = GETPOST('search_keyword', 'alphanohtml');
$pageNo = max(1, GETPOSTINT('page'));
$perPage = 20;
$onlyInstallable = GETPOSTINT('only_free') ? 1 : 0;

$form = new Form($db);
$dsClient = new DMMDolistoreClient($langs->getDefaultLang());

/*
 * Actions
 */

// Reset cache
if ($action == 'resetcache' && $user->hasRight('dolimodulemanager', 'admin')) {
	$cacheFile = (isset($conf->dolimodulemanager->dir_temp) ? $conf->dolimodulemanager->dir_temp : DOL_DATA_ROOT.'/dolimodulemanager/temp').'/dolistore_cache/products_'.$langs->getDefaultLang().'.json';
	@unlink($cacheFile);
	setEventMessages($langs->trans('DMMDolistoreCacheReset'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Add a DoliStore module to the DMM registry (no install yet).
if (($action == 'adddolistore' || $action == 'installdolistore') && $dolistoreId > 0 && $user->hasRight('dolimodulemanager', 'write')) {
	$product = $dsClient->findProductById($dolistoreId);
	if ($product === null) {
		setEventMessages($langs->trans('DMMDolistoreProductNotFound', $dolistoreId), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
	$normalized = $dsClient->normalizeProduct($product);
	$priceHt = (float) ($normalized['price_ht'] ?? 0);
	if ($priceHt > 0) {
		setEventMessages($langs->trans('DMMDolistoreOnlyFreeSupported'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// Generate a stable module_id from the product label (descriptor will refine it later).
	$moduleIdSeed = $normalized['label'] ?: ('dolistore'.$dolistoreId);
	$moduleId = strtolower(preg_replace('/[^a-z0-9_]/i', '', $moduleIdSeed));
	if ($moduleId === '') {
		$moduleId = 'dolistore'.$dolistoreId;
	}

	$existing = new DMMModule($db);
	$alreadyRegistered = ($existing->fetch(0, $moduleId) > 0);
	if (!$alreadyRegistered) {
		// Also check by dolistore_id in case the label hashed to a different id last time.
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
		$mod->name = $normalized['label'];
		$mod->description = $normalized['description'];
		$mod->url = $normalized['view_url'];
		$mod->dolistore_id = $dolistoreId;
		$created = $mod->create($user);
		if ($created < 0) {
			setEventMessages($mod->error ?: 'create failed', null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		// DMMModule::create() does not write the cache_* columns, so we seed
		// cache_latest_version through updateCache() right after the insert.
		// Otherwise the dashboard shows '-' until the user manually clicks
		// Check (and the row may not be re-checked for hours).
		if (!empty($normalized['module_version'])) {
			$mod->updateCache(array(
				'latest_version'    => $normalized['module_version'],
				'latest_compatible' => $normalized['module_version'],
			));
		}
	}

	// Reload the row to know its rowid (works whether we just inserted it or it
	// was already registered).
	$row = new DMMModule($db);
	$row->fetch(0, $moduleId);

	if ($action == 'installdolistore') {
		// Hand off to the standard DMM module page: it shows the install
		// confirmation, runs the same DoliStore-aware pipeline through
		// confirm_install, and gives users the full backup/restore UI on errors.
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $row->id).'&action=confirminstall&token='.newToken());
		exit;
	}

	setEventMessages($langs->trans('DMMDolistoreAdded', $normalized['label']), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * Build the displayed list
 */

$products = $dsClient->getAllProducts();
$totalRaw = count($products);
$err = $dsClient->error;

// Apply keyword filter (case-insensitive on label + description)
if ($searchKw !== '') {
	$products = array_values(array_filter($products, function ($p) use ($searchKw) {
		$haystack = strtolower(($p['label'] ?? '').' '.($p['description'] ?? ''));
		return strpos($haystack, strtolower($searchKw)) !== false;
	}));
}

// Apply free filter
if ($onlyInstallable) {
	$products = array_values(array_filter($products, function ($p) {
		return ((float) ($p['price_ht'] ?? 0)) === 0.0;
	}));
}

$totalFiltered = count($products);
$totalPages = max(1, (int) ceil($totalFiltered / $perPage));
$pageNo = min($pageNo, $totalPages);
$slice = array_slice($products, ($pageNo - 1) * $perPage, $perPage);

// Index of modules already in DMM registry (by dolistore_id)
$alreadyRegisteredIds = array();
$installedIds = array();
$sql = "SELECT dolistore_id, installed FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id IS NOT NULL";
$resq = $db->query($sql);
if ($resq) {
	while ($o = $db->fetch_object($resq)) {
		$alreadyRegisteredIds[(int) $o->dolistore_id] = true;
		if ((int) $o->installed === 1) {
			$installedIds[(int) $o->dolistore_id] = true;
		}
	}
}

/*
 * View
 */

$title = $langs->trans('DMMMarketplace');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-marketplace');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager'), $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'marketplace', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

print '<p class="opacitymedium">'.$langs->trans('DMMMarketplaceIntro').'</p>';

// Search form
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="paddingbottom">';
print '<input type="text" name="search_keyword" value="'.dol_escape_htmltag($searchKw).'" placeholder="'.$langs->trans('Keyword').'" class="minwidth200">';
print ' <label class="paddingleft"><input type="checkbox" name="only_free" value="1"'.($onlyInstallable ? ' checked' : '').'> '.$langs->trans('DMMOnlyFreeModules').'</label>';
print ' <input type="submit" class="button" value="'.$langs->trans('Search').'">';
if ($searchKw !== '' || $onlyInstallable) {
	print ' <a href="'.$_SERVER['PHP_SELF'].'" class="butActionDelete">'.$langs->trans('Reset').'</a>';
}
print ' <a href="'.$_SERVER['PHP_SELF'].'?action=resetcache&token='.newToken().'" class="butAction">'.$langs->trans('DMMRefreshCatalog').'</a>';
print '</form>';

if ($err !== '') {
	print info_admin($err, 0, 0, 'warning');
}

print '<div class="opacitymedium small paddingbottom">'.$langs->trans('DMMMarketplaceCount', $totalFiltered, $totalRaw).'</div>';

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="width150">'.$langs->trans('Module').'</th>';
print '<th>'.$langs->trans('Description').'</th>';
print '<th class="center width100">'.$langs->trans('Version').'</th>';
print '<th class="center width80">'.$langs->trans('Price').'</th>';
print '<th class="center width200">'.$langs->trans('Action').'</th>';
print '</tr>';

if (empty($slice)) {
	print '<tr><td colspan="5" class="center opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
}

foreach ($slice as $raw) {
	$p = $dsClient->normalizeProduct($raw);
	$isFree = $p['is_free_candidate'];
	$registered = isset($alreadyRegisteredIds[$p['id']]);
	$installed = isset($installedIds[$p['id']]);

	print '<tr class="oddeven">';

	// Logo
	print '<td class="center">';
	if (!empty($p['cover_photo_url'])) {
		print '<img src="'.dol_escape_htmltag($p['cover_photo_url']).'" alt="" style="max-width:100px;max-height:80px;">';
	}
	print '</td>';

	// Description
	print '<td>';
	print '<strong>'.dolPrintHTML($p['label']).'</strong><br>';
	print '<span class="small opacitymedium">'.dolPrintHTML(dol_string_nohtmltag($p['description'])).'</span><br>';
	print '<small class="opacitymedium">'.$langs->trans('Compatibility').': Dolibarr '.dol_escape_htmltag($p['dolibarr_min']).' &rarr; '.dol_escape_htmltag($p['dolibarr_max']).'</small>';
	print '</td>';

	// Version
	print '<td class="center">'.dol_escape_htmltag($p['module_version']).'</td>';

	// Price
	print '<td class="center">';
	if ($isFree) {
		print '<span class="badge badge-status4 badge-status">'.$langs->trans('Free').'</span>';
	} else {
		print price($p['price_ht'], 0, $langs, 1, -1, -1, 'EUR').' '.$langs->trans('HT');
	}
	print '</td>';

	// Actions
	print '<td class="center">';
	print '<a href="'.dol_escape_htmltag($p['view_url']).'" target="_blank" rel="noopener noreferrer" class="butAction" title="'.$langs->trans('View').'">'.img_picto('', 'url', 'class="paddingright"').'</a>';
	if ($isFree && !$installed) {
		$installLabel = $registered ? $langs->trans('Update') : $langs->trans('Install');
		print '<a href="'.$_SERVER['PHP_SELF'].'?action=installdolistore&dolistore_id='.((int) $p['id']).'&token='.newToken().'" class="butAction" title="'.$installLabel.'">'.img_picto('', 'download', 'class="paddingright"').' '.$installLabel.'</a>';
	}
	if ($isFree && $installed) {
		print '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>';
	}
	if (!$isFree) {
		print '<span class="opacitymedium small">'.$langs->trans('DMMBuyOnDolistore').'</span>';
	}
	print '</td>';

	print '</tr>';
}
print '</table>';
print '</div>';

// Pagination
if ($totalPages > 1) {
	print '<div class="pagination paddingtop">';
	$base = $_SERVER['PHP_SELF'].'?search_keyword='.urlencode($searchKw).($onlyInstallable ? '&only_free=1' : '').'&page=';
	for ($i = 1; $i <= $totalPages; $i++) {
		if ($i === $pageNo) {
			print '<strong>'.$i.'</strong> ';
		} else {
			print '<a href="'.$base.$i.'">'.$i.'</a> ';
		}
	}
	print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
