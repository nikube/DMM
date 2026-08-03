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

// Add a DoliStore product by URL or id. Free modules never appear in the order
// history, so this is the only way to reach them without browsing the catalog.
if ($action == 'adddolistore' && dmm_user_can('write')) {
	$pid = dmm_parse_dolistore_id(GETPOST('product_url', 'restricthtml'));

	if ($pid <= 0) {
		setEventMessages($langs->trans('DMMAddByUrlBad'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// Resolve the label from the catalog when it is already cached, so the row gets
	// a real name. A cold cache is not worth a multi-second download here.
	$dsLookup = new DMMDolistoreClient($langs->defaultlang);
	$label = '';
	if ($dsLookup->isCatalogCached()) {
		$found = $dsLookup->findProductById($pid);
		if ($found === null) {
			setEventMessages($langs->trans('DMMAddByUrlNotFound', $pid), null, 'errors');
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;
		}
		$label = (string) ($found['label'] ?? '');
	}

	$sqlDup = "SELECT rowid, module_id FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id = ".((int) $pid);
	$resDup = $db->query($sqlDup);
	if ($resDup && $db->num_rows($resDup) > 0) {
		$o = $db->fetch_object($resDup);
		setEventMessages($langs->trans('DMMAddByUrlAlready', $o->module_id), null, 'mesgs');
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $o->rowid));
		exit;
	}

	$seed = $label !== '' ? $label : ('dolistore'.$pid);
	$moduleId = strtolower(preg_replace('/[^a-z0-9_]/i', '', $seed));
	if ($moduleId === '') {
		$moduleId = 'dolistore'.$pid;
	}
	$probe = new DMMModule($db);
	if ($probe->fetch(0, $moduleId) > 0) {
		$moduleId = 'dolistore'.$pid;
	}

	$mod = new DMMModule($db);
	$mod->module_id = $moduleId;
	$mod->github_repo = 'dolistore:'.$pid;
	$mod->source = 'dolistore';
	$mod->name = $label !== '' ? $label : ('DoliStore #'.$pid);
	$mod->url = DMMDolistoreSession::SHOP_URL.'/product.php?id='.$pid;
	$mod->dolistore_id = $pid;
	$created = $mod->create($user);
	if ($created > 0) {
		setEventMessages($langs->trans('DMMAddByUrlAdded', $mod->name), null, 'mesgs');
		// Straight to the module card: that page carries the install pipeline.
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $created));
		exit;
	}
	setEventMessages($mod->error ?: 'create failed', null, 'errors');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Import every module a hub advertises.
if ($action == 'importhub' && dmm_user_can('write')) {
	$hubUrl = trim((string) GETPOST('hub_url', 'restricthtml'));
	if ($hubUrl !== '') {
		$report = $dmmClient->importFromHub($hubUrl);
		dmm_show_hub_report($report);
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

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

print '<div class="opacitymedium">'.$langs->trans('DMMAddModuleIntro').'</div><br>';

// ---- 1. From a git repository ----
print '<div class="fichecenter"><a id="repo"></a>';
print '<h3>'.img_picto('', 'fa-code-branch', 'class="pictofixedwidth"').$langs->trans('DMMAddFromRepo').'</h3>';
print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromRepoHelp').'</div>';
if (dmm_user_can('write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addpublicrepo">';
	print '<input type="text" name="public_repo" class="minwidth400 maxwidth600" placeholder="owner/repo">';
	print ' <input type="submit" class="button button-save" value="'.$langs->trans('Add').'">';
	print '</form>';
}
print '</div><br>';

// ---- 2. From DoliStore, by product link or id ----
print '<div class="fichecenter"><a id="dolistore"></a>';
print '<h3>'.img_picto('', 'fa-shopping-cart', 'class="pictofixedwidth"').$langs->trans('DMMAddFromDolistore').'</h3>';
print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromDolistoreHelp').'</div>';
print '<div class="paddingtop"><a href="https://www.dolistore.com/index.php?cat=67&title=modules-plugins&l='.substr($langs->defaultlang, 0, 2).'" target="_blank" rel="noopener noreferrer">'.$langs->trans('DMMSearchOnDolistore').' '.img_picto('', 'url').'</a></div>';
if (dmm_user_can('write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="paddingtop">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="adddolistore">';
	print '<input type="text" name="product_url" class="minwidth400 maxwidth600" placeholder="'.dol_escape_htmltag($langs->trans('DMMAddByUrlPlaceholder')).'">';
	print ' <input type="submit" class="button button-save" value="'.$langs->trans('Add').'">';
	print '</form>';
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

// ---- 4. From a hub ----
print '<div class="fichecenter"><a id="hub"></a>';
print '<h3>'.img_picto('', 'fa-cubes', 'class="pictofixedwidth"').$langs->trans('DMMAddFromHub').'</h3>';
print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromHubHelp').'</div>';

$hubs = function_exists('dmm_get_hubs') ? dmm_get_hubs() : array();
$enabledHubs = array();
foreach ($hubs as $h) {
	if (!empty($h['enabled'])) {
		$enabledHubs[] = $h;
	}
}
if (empty($enabledHubs)) {
	print '<div class="opacitymedium">'.$langs->trans('DMMNoHubEnabled').'</div>';
} else {
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Name').'</th><th>URL</th><th class="center width150">'.$langs->trans('Action').'</th></tr>';
	foreach ($enabledHubs as $h) {
		// dmm_get_hubs() only carries url + enabled; the hub's own name lives inside
		// its JSON and is not worth a fetch here. Fall back to the host.
		$hubLabel = $h['name'] ?? (parse_url($h['url'], PHP_URL_HOST) ?: $h['url']);
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($hubLabel).'</td>';
		print '<td class="tdoverflowmax300"><small class="opacitymedium">'.dol_escape_htmltag($h['url']).'</small></td>';
		print '<td class="center">';
		if (dmm_user_can('write')) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="importhub">';
			print '<input type="hidden" name="hub_url" value="'.dol_escape_htmltag($h['url']).'">';
			print '<input type="submit" class="button button-save small" value="'.$langs->trans('DMMImport').'">';
			print '</form>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';
}
print '<div class="paddingtop opacitymedium small">'.$langs->trans('DMMManageHubsInSources').' <a href="'.dol_buildpath('/dolimodulemanager/admin/sources.php', 1).'">'.$langs->trans('DMMSourcesTab').'</a></div>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
