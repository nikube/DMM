<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/add.php
 * \ingroup dolimodulemanager
 * \brief   "Add a module" tab: the single way in for a module that is not on
 *          this Dolibarr yet, whatever its origin — a hub, DoliStore, a
 *          purchase, or a repository typed by hand.
 *
 *          The dashboard answers "what do I have"; this page answers "what do I
 *          want to add". Splitting on that axis is what replaced the former
 *          Marketplace and Purchases tabs, which were split on free-vs-paid — a
 *          boundary that never held, since the order history lists only paid
 *          modules and free ones had to be added by URL anyway.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMDolistoreClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

dmm_require_right('read');

$action = GETPOST('action', 'aZ09');
$form = new Form($db);
$dmmClient = new DMMClient($db);
$dmmModule = new DMMModule($db);

// Catalog browsing state
$searchKw = trim((string) GETPOST('search', 'alphanohtml'));
$freeOnly = (GETPOSTINT('freeonly') === 1);
$page = max(1, GETPOSTINT('page'));
$perPage = 25;

/*
 * Actions
 */

// Add a module from a git repository (GitHub shortcut or full URL, GitLab included).
if ($action == 'addpublicrepo' && dmm_user_can('write')) {
	$repoInput = trim((string) GETPOST('public_repo', 'restricthtml'));

	// Accept a flat "owner/repo" GitHub shortcut OR a full git URL
	// (github.com or a self-hosted GitLab instance, nested groups supported).
	$parsed = $dmmClient->parsePublicRepoInput($repoInput);

	if ($parsed === null) {
		setEventMessages($langs->trans('DMMErrorRepoFormat'), null, 'errors');
	} else {
		$repoName = $parsed['repo'];

		// GitHub public repos can be probed for the manifest without a token.
		// For GitLab we create the row without a manifest — the first Check
		// resolves version/manifest host-aware (avoids guessing the branch here).
		$manifest = array();
		if ($parsed['host'] === 'github') {
			$manifest = $dmmClient->fetchManifest($parsed['owner'], $repoName, null);
			if (!is_array($manifest)) {
				$manifest = array();
			}
		}

		$module_id = $manifest['module_id'] ?? strtolower(preg_replace('/[^a-z0-9_]/i', '', $repoName));

		$existing = new DMMModule($db);
		if ($existing->fetch(0, $module_id) > 0) {
			setEventMessages($langs->trans('DMMModuleAlreadyRegistered', $module_id), null, 'warnings');
		} else {
			$mod = new DMMModule($db);
			$mod->module_id = $module_id;
			$mod->github_repo = $parsed['project'];
			$mod->git_host = $parsed['host'];
			$mod->git_base_url = $parsed['base_url'];
			$mod->subdir = $parsed['subdir'];
			$mod->fk_dmm_token = null;
			$mod->name = $manifest['name'] ?? null;
			$mod->description = $manifest['description'] ?? null;
			$mod->author = $manifest['author'] ?? null;
			$mod->license = $manifest['license'] ?? null;
			$mod->url = $manifest['url'] ?? null;

			// Already on disk? Then it is installed, and the descriptor knows which
			// version. getInstalledVersion() handles the three descriptor spellings —
			// the inline regex this replaced only matched the literal one.
			$installedVersion = $dmmClient->getInstalledVersion($module_id);
			if ($installedVersion !== null || is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$module_id)) {
				$mod->installed = 1;
				$mod->installed_version = $installedVersion;
			}

			if ($mod->create($user) > 0) {
				setEventMessages($langs->trans('DMMRepoAdded', $parsed['project']), null, 'mesgs');
			} else {
				setEventMessages($mod->error, null, 'errors');
			}
		}
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Register a DoliStore product, from the catalog listing or from a pasted link.
// 'installdolistore' additionally hands off to the module card's install flow.
if (($action == 'adddolistore' || $action == 'installdolistore') && dmm_user_can('write')) {
	// Accepts a bare id (catalog buttons) or a pasted product URL (the form).
	$pid = dmm_parse_dolistore_id(GETPOST('product_url', 'restricthtml') ?: GETPOSTINT('dolistore_id'));

	if ($pid <= 0) {
		setEventMessages($langs->trans('DMMAddByUrlBad'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// The catalog gives the label, description and version. A cold cache is not
	// worth a multi-second download here, so fall back to a bare id-based row.
	$dsLookup = new DMMDolistoreClient($langs->defaultlang);
	$normalized = null;
	if ($dsLookup->isCatalogCached()) {
		$product = $dsLookup->findProductById($pid);
		if ($product === null) {
			setEventMessages($langs->trans('DMMAddByUrlNotFound', $pid), null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		$normalized = $dsLookup->normalizeProduct($product);
	}
	$label = $normalized !== null ? (string) $normalized['label'] : '';

	// Seed module_id from the label; the descriptor refines it after extraction.
	$moduleId = strtolower(preg_replace('/[^a-z0-9_]/i', '', $label !== '' ? $label : ('dolistore'.$pid)));
	if ($moduleId === '') {
		$moduleId = 'dolistore'.$pid;
	}

	$existing = new DMMModule($db);
	$alreadyRegistered = ($existing->fetch(0, $moduleId) > 0);
	if (!$alreadyRegistered) {
		// Also check by dolistore_id, in case the label hashed to a different id
		// last time round.
		$sqlCheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $pid);
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
		$mod->github_repo = 'dolistore:'.$pid;
		$mod->source = 'dolistore';
		$mod->name = $label !== '' ? $label : ('DoliStore #'.$pid);
		$mod->description = $normalized !== null ? $normalized['description'] : null;
		$mod->url = $normalized !== null ? $normalized['view_url'] : (DMMDolistoreSession::SHOP_URL.'/product.php?id='.$pid);
		$mod->dolistore_id = $pid;
		if ($mod->create($user) < 0) {
			setEventMessages($mod->error ?: 'create failed', null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		// DMMModule::create() does not write the cache_* columns, so seed
		// cache_latest_version here — otherwise the module shows '-' as its latest
		// version until someone clicks Check by hand.
		if ($normalized !== null && !empty($normalized['module_version'])) {
			$mod->updateCache(array(
				'latest_version'    => $normalized['module_version'],
				'latest_compatible' => $normalized['module_version'],
			));
		}
	}

	$row = new DMMModule($db);
	$row->fetch(0, $moduleId);

	if ($action == 'installdolistore') {
		// Hand off to the module card: it shows the install confirmation, runs the
		// DoliStore-aware pipeline and gives the full backup/restore UI on errors.
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $row->id).'&action=confirminstall&token='.newToken());
		exit;
	}

	setEventMessages($alreadyRegistered
		? $langs->trans('DMMAddByUrlAlready', $moduleId)
		: $langs->trans('DMMDolistoreAdded', $row->name), null, 'mesgs');
	header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $row->id));
	exit;
}

// Warm the catalog cache on demand. ~1700 products over 9 parallel page fetches:
// a few seconds, and good for 24h — but never inline on page load.
if ($action == 'loadcatalog' && dmm_user_can('write')) {
	if (function_exists('session_write_close')) {
		// Otherwise this request holds the session lock and every other request
		// from the same user blocks until the download finishes.
		@session_write_close();
	}
	@ignore_user_abort(true);
	@set_time_limit(120);
	$dsWarm = new DMMDolistoreClient($langs->defaultlang);
	$dsWarm->getAllProducts(true);
	if (GETPOSTINT('ajax') || dmm_is_ajax_request()) {
		dmm_ajax_response(array('success' => empty($dsWarm->error), 'error' => (string) $dsWarm->error));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#dolistore');
	exit;
}

// Hub imports live on the Sources tab, which is where hubs are configured.

// Refresh the cached purchase list.
if ($action == 'refreshpurchases' && dmm_user_can('read')) {
	$baseTemp = isset($conf->dolimodulemanager->dir_temp)
		? $conf->dolimodulemanager->dir_temp
		: DOL_DATA_ROOT.'/dolimodulemanager/temp';
	@unlink($baseTemp.'/dolistore_purchases.json');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Install a purchased module. The wrapper URL is long and carries a per-order
// key, so the listing submits an md5() of it and we resolve it back from cache.
if ($action == 'installpurchase' && dmm_user_can('write')) {
	$dolistoreId = GETPOSTINT('dolistore_id');
	$wrapperHash = GETPOST('wh', 'alphanohtml');

	$wrapperUrl = null;
	$seedName = '';
	foreach (dmm_scan_load_purchases($db) as $p) {
		if (empty($p['zip_url'])) {
			continue;
		}
		if ((int) ($p['id'] ?? 0) === $dolistoreId && md5($p['zip_url']) === $wrapperHash) {
			$wrapperUrl = $p['zip_url'];
			$seedName = $p['name'] ?? '';
			break;
		}
	}
	if ($wrapperUrl === null) {
		setEventMessages($langs->trans('DMMPurchasesWrapperExpired'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// Seed module_id from the product name; the descriptor refines it post-extract.
	$moduleId = strtolower(preg_replace('/[^a-z0-9_]/i', '', $seedName !== '' ? $seedName : ('dolistore'.$dolistoreId)));
	if ($moduleId === '') {
		$moduleId = 'dolistore'.$dolistoreId;
	}

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
		if ($mod->create($user) < 0) {
			setEventMessages($mod->error ?: 'create failed', null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
	}

	$result = $dmmClient->installFromDolistorePurchase($moduleId, $dolistoreId, $wrapperUrl);
	if (!empty($result['success'])) {
		setEventMessages($result['message'], null, 'mesgs');
		// The descriptor may have renamed the row — follow dolistore_id to the
		// canonical one and hand over to the module card.
		$sqlR = "SELECT rowid FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $dolistoreId);
		$resR = $db->query($sqlR);
		if ($resR && $db->num_rows($resR) > 0) {
			$o = $db->fetch_object($resR);
			header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $o->rowid));
			exit;
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

$title = $langs->trans('DMMAddModule');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-add');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager'), $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'add', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- Direct entry points, side by side ----
// The two paste-a-link forms and the hub imports are one-liners; keeping them
// compact at the top leaves the page to the catalog, which is what needs room.
print '<style>
.dmm-add-bar { display: flex; flex-wrap: wrap; gap: 18px; align-items: flex-start; margin-bottom: 6px; }
.dmm-add-bar > div { flex: 1 1 300px; min-width: 280px; }
.dmm-add-bar .dmm-add-label { font-weight: bold; display: block; margin-bottom: 4px; }
.dmm-add-bar form { margin: 0; }
.dmm-add-bar input[type=text] { max-width: 100%; }
.dmm-add-hint { display: block; margin-top: 2px; }
/* Catalog action cells: one row, two fixed slots, so buttons align down the
   column instead of shifting with each label length. */
.dmm-cat-actions { white-space: nowrap; }
.dmm-cat-slot { display: inline-block; vertical-align: middle; }
.dmm-cat-slot:first-child { width: 42px; }
.dmm-cat-slot:last-child { width: 92px; }
.dmm-cat-slot .butAction { margin: 0; float: none; display: inline-block; }
/* Same as the dashboard: the theme reserves 20px/40px around .tabsAction and
   16px !important under each button, sized for a card footer rather than a
   single button mid-page. */
.page-admin-add .tabsAction { margin-top: 8px; margin-bottom: 6px; }
.page-admin-add .tabsAction > a { margin-bottom: 0 !important; }
</style>';

print '<div class="dmm-add-bar">';

// Git repository
print '<div><a id="repo"></a>';
print '<span class="dmm-add-label">'.img_picto('', 'fa-code-branch', 'class="pictofixedwidth"').$langs->trans('DMMAddFromRepo').'</span>';
if (dmm_user_can('write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addpublicrepo">';
	print '<input type="text" name="public_repo" class="minwidth200" placeholder="owner/repo">';
	print ' <input type="submit" class="button button-save small" value="'.$langs->trans('Add').'">';
	print '</form>';
}
print '<span class="opacitymedium small dmm-add-hint">'.$langs->trans('DMMAddFromRepoHelp').'</span>';
print '</div>';

// DoliStore product link or id
print '<div>';
print '<span class="dmm-add-label">'.img_picto('', 'fa-link', 'class="pictofixedwidth"').$langs->trans('DMMAddByProductLink').'</span>';
if (dmm_user_can('write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="adddolistore">';
	print '<input type="text" name="product_url" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('DMMAddByUrlPlaceholder')).'">';
	print ' <input type="submit" class="button button-save small" value="'.$langs->trans('Add').'">';
	print '</form>';
}
print '<span class="opacitymedium small dmm-add-hint">'.$langs->trans('DMMAddByProductLinkHelp').'</span>';
print '</div>';

// Hubs — configured and imported from the Sources tab; no point duplicating the
// list and its import buttons here.
print '<div><a id="hub"></a>';
print '<span class="dmm-add-label">'.img_picto('', 'fa-cubes', 'class="pictofixedwidth"').$langs->trans('DMMAddFromHub').'</span>';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/sources.php', 1).'">'.$langs->trans('DMMSourcesTab').'</a>';
print '<span class="opacitymedium small dmm-add-hint">'.$langs->trans('DMMAddFromHubHelp').'</span>';
print '</div>';

print '</div>'; // .dmm-add-bar
print '<div class="clearboth"></div><br>';

// ---- The DoliStore catalog ----
print '<div class="fichecenter"><a id="dolistore"></a>';
print '<h3>'.img_picto('', 'fa-shopping-cart', 'class="pictofixedwidth"').$langs->trans('DMMAddFromDolistore').'</h3>';

$dsCatalog = new DMMDolistoreClient($langs->defaultlang);
if (!$dsCatalog->isCatalogCached()) {
	// ~1700 products is seconds of network: never inline, or the page hangs on it.
	// A drawer that fetches on click keeps the rest of the page usable meanwhile,
	// and once cached the catalog stays for 24h so this is a one-off.
	print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromDolistoreHelp').'</div>';
	print '<div class="paddingtop opacitymedium" id="dmmCatalogIdle">'.$langs->trans('DMMCatalogNotLoaded').'</div>';
	if (dmm_user_can('write')) {
		$warmUrl = $_SERVER['PHP_SELF'].'?action=loadcatalog&token='.newToken();
		print '<div class="paddingtop"><a class="butAction" id="dmmLoadCatalog" href="'.$warmUrl.'#dolistore">'.$langs->trans('DMMLoadCatalog').'</a></div>';
		print '<div id="dmmCatalogLoading" style="display:none" class="paddingtop">';
		print '<span class="opacitymedium">'.img_picto('', 'fa-spinner', 'class="fa-spin pictofixedwidth"').$langs->trans('DMMCatalogLoading').'</span>';
		print '</div>';
		$nonce = function_exists('getNonce') ? ' nonce="'.getNonce().'"' : '';
		print '<script'.$nonce.'>
(function () {
	var btn = document.getElementById("dmmLoadCatalog");
	if (!btn) { return; }
	btn.addEventListener("click", function (e) {
		e.preventDefault();
		// Swap the button for a spinner rather than freezing on a full page load.
		btn.style.display = "none";
		var idle = document.getElementById("dmmCatalogIdle");
		if (idle) { idle.style.display = "none"; }
		var busy = document.getElementById("dmmCatalogLoading");
		if (busy) { busy.style.display = "block"; }
		fetch('.json_encode($warmUrl.'&ajax=1').', {
			credentials: "same-origin",
			headers: {"X-Requested-With": "XMLHttpRequest", "Accept": "application/json"}
		}).then(function () {
			window.location.href = '.json_encode($_SERVER['PHP_SELF'].'#dolistore').';
		}).catch(function () {
			window.location.href = '.json_encode($_SERVER['PHP_SELF'].'#dolistore').';
		});
	});
}());
</script>';
	}
} else {
	$products = $dsCatalog->getAllProducts();

	// Which products does DMM already track?
	$knownDs = array();
	$sqlDs = "SELECT dolistore_id, installed FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id IS NOT NULL";
	$resDs = $db->query($sqlDs);
	if ($resDs) {
		while ($o = $db->fetch_object($resDs)) {
			$knownDs[(int) $o->dolistore_id] = (int) $o->installed;
		}
	}

	if ($searchKw !== '') {
		$needle = strtolower($searchKw);
		$products = array_values(array_filter($products, function ($p) use ($needle) {
			return strpos(strtolower(($p['label'] ?? '').' '.($p['description'] ?? '')), $needle) !== false;
		}));
	}
	if ($freeOnly) {
		$products = array_values(array_filter($products, function ($p) {
			return ((float) ($p['price_ht'] ?? 0)) === 0.0;
		}));
	}

	$total = count($products);
	$pageCount = max(1, (int) ceil($total / $perPage));
	$page = min($page, $pageCount);
	$slice = array_slice($products, ($page - 1) * $perPage, $perPage);

	// Search + filter
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="paddingtop">';
	print '<input type="text" name="search" value="'.dol_escape_htmltag($searchKw).'" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('DMMSearchCatalog')).'">';
	print ' <label class="opacitymedium small"><input type="checkbox" name="freeonly" value="1"'.($freeOnly ? ' checked' : '').'> '.$langs->trans('DMMFreeOnly').'</label>';
	print ' <input type="submit" class="button button-save small" value="'.$langs->trans('Search').'">';
	if ($searchKw !== '' || $freeOnly) {
		print ' <a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'#dolistore">'.$langs->trans('Reset').'</a>';
	}
	print '</form>';

	print '<div class="opacitymedium small paddingtop">'.$langs->trans('DMMCatalogCount', $total).'</div>';

	// The web listing carries no module_version at all (the public API does, at the
	// cost of ~40s per refresh — see the catalog source setting in Advanced). Drop
	// the column rather than print an empty one on every row.
	$hasVersions = false;
	foreach ($slice as $raw) {
		if (!empty($raw['module_version'])) {
			$hasVersions = true;
			break;
		}
	}
	$colCount = $hasVersions ? 5 : 4;

	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th class="width150">'.$langs->trans('Module').'</th>';
	print '<th>'.$langs->trans('Description').'</th>';
	if ($hasVersions) {
		print '<th class="center width100">'.$langs->trans('Version').'</th>';
	}
	print '<th class="center width80">'.$langs->trans('Price').'</th>';
	print '<th class="center width200">'.$langs->trans('Action').'</th>';
	print '</tr>';

	if (empty($slice)) {
		print '<tr><td colspan="'.$colCount.'" class="center opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	foreach ($slice as $raw) {
		$p = $dsCatalog->normalizeProduct($raw);
		$pid = (int) $p['id'];
		$isFree = $p['is_free_candidate'];
		$registered = isset($knownDs[$pid]);
		$installed = $registered && $knownDs[$pid];

		print '<tr class="oddeven">';

		// Cover
		print '<td class="center">';
		if (!empty($p['cover_photo_url'])) {
			print '<img src="'.dol_escape_htmltag($p['cover_photo_url']).'" alt="" style="max-width:100px;max-height:80px;">';
		}
		print '</td>';

		// Label + description + compatibility
		print '<td>';
		print '<strong>'.dolPrintHTML($p['label']).'</strong><br>';
		print '<span class="small opacitymedium">'.dolPrintHTML(dol_string_nohtmltag($p['description'])).'</span><br>';
		print '<small class="opacitymedium">'.$langs->trans('Compatibility').': Dolibarr '.dol_escape_htmltag($p['dolibarr_min']).' &rarr; '.dol_escape_htmltag($p['dolibarr_max']).'</small>';
		print '</td>';

		if ($hasVersions) {
			print '<td class="center">'.dol_escape_htmltag($p['module_version']).'</td>';
		}

		print '<td class="center">';
		if ($isFree) {
			print '<span class="badge badge-status4 badge-status">'.$langs->trans('DMMPriceFree').'</span>';
		} else {
			print price($p['price_ht'], 0, $langs, 1, -1, -1, 'EUR').' '.$langs->trans('HT');
		}
		print '</td>';

		// Actions — two fixed-width slots so every row's buttons line up and none
		// wraps onto a second line when a label happens to be longer.
		print '<td class="center nowraponall dmm-cat-actions">';
		print '<span class="dmm-cat-slot"><a href="'.dol_escape_htmltag($p['view_url']).'" target="_blank" rel="noopener noreferrer" class="butAction butActionSmall" title="'.$langs->trans('View').'">'.img_picto('', 'url').'</a></span>';
		print '<span class="dmm-cat-slot">';
		if ($installed) {
			print '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>';
		} elseif ($isFree && dmm_user_can('write')) {
			// Free modules install straight through DMM.
			$installLabel = $registered ? $langs->trans('Update') : $langs->trans('Install');
			print '<a href="'.$_SERVER['PHP_SELF'].'?action=installdolistore&dolistore_id='.$pid.'&token='.newToken().'" class="butAction butActionSmall" title="'.$installLabel.'">'.$installLabel.'</a>';
		} elseif (!$isFree) {
			// Paid: buy it on DoliStore, then DMM installs it from your purchases.
			if ($registered) {
				print '<span class="opacitymedium small">'.$langs->trans('DMMAlreadyTracked').'</span>';
			} elseif (dmm_user_can('write')) {
				print '<a href="'.$_SERVER['PHP_SELF'].'?action=adddolistore&dolistore_id='.$pid.'&token='.newToken().'" class="butAction butActionSmall" title="'.dol_escape_htmltag($langs->trans('DMMTrackThisModule')).'">'.$langs->trans('Add').'</a>';
			}
		}
		print '</span>';
		print '</td>';

		print '</tr>';
	}
	print '</table></div>';

	// Pagination
	if ($pageCount > 1) {
		$qs = ($searchKw !== '' ? '&search='.urlencode($searchKw) : '').($freeOnly ? '&freeonly=1' : '');
		print '<div class="center paddingtop">';
		if ($page > 1) {
			print '<a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'?page='.($page - 1).$qs.'#dolistore">&laquo;</a> ';
		}
		print '<span class="opacitymedium small">'.$langs->trans('Page').' '.$page.' / '.$pageCount.'</span>';
		if ($page < $pageCount) {
			print ' <a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'?page='.($page + 1).$qs.'#dolistore">&raquo;</a>';
		}
		print '</div>';
	}
}
print '</div><br>';

// ---- 3. My DoliStore purchases ----
print '<div class="fichecenter"><a id="purchases"></a>';
print '<h3>'.img_picto('', 'fa-shopping-bag', 'class="pictofixedwidth"').$langs->trans('DMMPurchases').'</h3>';

$dsSession = new DMMDolistoreSession($db);
if (!$dsSession->hasCredentials()) {
	print '<div class="opacitymedium">'.$langs->trans('DMMConfigureDolistoreCreds').'</div>';
	print '<div class="paddingtop"><a href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'#dolistore" class="butAction">'.$langs->trans('DMMOpenSetup').'</a></div>';
} else {
	$purchases = dmm_scan_load_purchases($db);
	print '<div class="tabsAction">';
	print '<a href="'.$_SERVER['PHP_SELF'].'?action=refreshpurchases&token='.newToken().'" class="butAction">'.img_picto('', 'refresh', 'class="paddingright"').$langs->trans('DMMRefreshPurchases').'</a>';
	print '</div>';

	if (empty($purchases)) {
		print '<div class="opacitymedium">'.$langs->trans('DMMNoPurchasesFound').'</div>';
	} else {
		// Which purchases are already tracked?
		$installedMap = array();
		$sql = "SELECT dolistore_id, installed, installed_version FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id IS NOT NULL";
		$resSql = $db->query($sql);
		if ($resSql) {
			while ($o = $db->fetch_object($resSql)) {
				$installedMap[(int) $o->dolistore_id] = array('installed' => (int) $o->installed, 'version' => $o->installed_version);
			}
		}

		print '<div class="div-table-responsive"><table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th>'.$langs->trans('Module').'</th>';
		print '<th class="center width150">'.$langs->trans('Order').'</th>';
		print '<th class="center width120">'.$langs->trans('Status').'</th>';
		print '<th class="center width200">'.$langs->trans('Action').'</th>';
		print '</tr>';
		foreach ($purchases as $p) {
			$pid = (int) ($p['id'] ?? 0);
			$row = isset($installedMap[$pid]) ? $installedMap[$pid] : null;
			$isInstalled = $row && $row['installed'];
			print '<tr class="oddeven">';
			print '<td><strong>'.dolPrintHTML($p['name']).'</strong>';
			if ($pid > 0) {
				print ' <a href="'.DMMDolistoreSession::SHOP_URL.'/product.php?id='.$pid.'" target="_blank" rel="noopener noreferrer" class="opacitymedium small">'.img_picto('', 'url').'</a>';
			}
			print '</td>';
			print '<td class="center"><small>'.dol_escape_htmltag($p['ref'] ?? '').'</small></td>';
			print '<td class="center">';
			print $isInstalled
				? '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>'
				: '<span class="opacitymedium small">'.$langs->trans('NotInstalled').'</span>';
			print '</td>';
			print '<td class="center">';
			if (!empty($p['zip_url']) && $pid > 0 && dmm_user_can('write')) {
				$wh = md5($p['zip_url']);
				$label = $isInstalled ? $langs->trans('Update') : $langs->trans('Install');
				print '<a href="'.$_SERVER['PHP_SELF'].'?action=installpurchase&dolistore_id='.$pid.'&wh='.$wh.'&token='.newToken().'" class="butAction">'.img_picto('', 'download', 'class="paddingright"').' '.$label.'</a>';
			} else {
				print '<span class="opacitymedium small">'.$langs->trans('DMMNoDownloadAvailable').'</span>';
			}
			print '</td>';
			print '</tr>';
		}
		print '</table></div>';
	}
}
print '</div><br>';

// ---- 4. Already known to DMM, not installed ----
// Registry rows with no files: modules a hub or a token surfaced, that the user
// could install. They used to sit on the dashboard, where they drowned out the
// modules actually installed — and would drown them completely once a large hub
// is imported. They are candidates, so they belong here.
$onDiskNow = $dmmClient->listInstalledOnDisk();
$available = array();
foreach ($dmmModule->fetchAll('all') as $r) {
	if ($r->module_id === 'dolimodulemanager' || isset($onDiskNow[$r->module_id])) {
		continue;
	}
	$available[] = $r;
}
if (!empty($available)) {
	print '<div class="fichecenter"><a id="known"></a>';
	print '<h3>'.img_picto('', 'fa-list', 'class="pictofixedwidth"').$langs->trans('DMMAddKnownModules').' <span class="badge badge-secondary">'.count($available).'</span></h3>';
	print '<div class="opacitymedium small">'.$langs->trans('DMMAddKnownModulesHelp').'</div>';
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Module').'</th>';
	print '<th class="tdoverflowmax200">'.$langs->trans('DMMSourceURL').'</th>';
	print '<th class="center width120">'.$langs->trans('DMMLatestVersion').'</th>';
	print '<th class="center width150">'.$langs->trans('Action').'</th>';
	print '</tr>';
	foreach ($available as $r) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$r->id.'">'.dol_escape_htmltag($r->module_id).'</a>';
		if (!empty($r->name) && $r->name !== $r->module_id) {
			print '<br><small class="opacitymedium">'.dol_escape_htmltag($r->name).'</small>';
		}
		print '</td>';
		print '<td class="tdoverflowmax200"><small class="opacitymedium">'.dol_escape_htmltag($r->github_repo).'</small></td>';
		print '<td class="center">'.dol_escape_htmltag($r->cache_latest_compatible ?: '-').'</td>';
		print '<td class="center">';
		print '<a class="butAction butActionSmall" href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$r->id.'">'.$langs->trans('DMMDetails').'</a>';
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';
	print '</div><br>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
