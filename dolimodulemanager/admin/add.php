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
$catalogSource = GETPOST('catalog', 'aZ09');
if (!in_array($catalogSource, array('community', 'dolistore', 'hub', 'purchases'), true)) {
	$catalogSource = 'community';
}
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
	header('Location: '.$_SERVER['PHP_SELF'].'');
	exit;
}

// Register a module from the Dolibarr community index.yaml.
//
// These entries track a branch, not tags: several live as subdirectories of one
// shared repo following main. So the branch and subdir from the YAML are stored
// on the row and the channel set to dev, or update checks would look for
// releases that do not exist.
if ($action == 'addcommunity' && dmm_user_can('write')) {
	$wanted = trim((string) GETPOST('mid', 'alphanohtml'));
	$cfg = dmm_get_community_yaml_config();
	$entries = $client = null;
	$entries = $dmmClient->fetchCommunityYaml($cfg['url']);
	if (!is_array($entries)) {
		setEventMessages($dmmClient->error ?: $langs->trans('DMMCommunityFetchFailed'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=community');
		exit;
	}

	$entry = null;
	foreach ($entries as $e) {
		if ((string) ($e['modulename'] ?? '') === $wanted) {
			$entry = $e;
			break;
		}
	}
	if ($entry === null || empty($entry['git'])) {
		setEventMessages($langs->trans('DMMCommunityEntryNotFound', $wanted), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=community');
		exit;
	}

	// Handles both a plain repo URL and a /tree/<branch>/<subdir> pointer.
	$parsed = $dmmClient->parsePublicRepoInput($entry['git']);
	if ($parsed === null) {
		setEventMessages($langs->trans('DMMErrorRepoFormat'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=community');
		exit;
	}

	$moduleId = $dmmClient->sanitizeModuleId((string) $entry['modulename']);
	if ($moduleId === false || $moduleId === '') {
		setEventMessages($langs->trans('DMMErrorRepoFormat'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=community');
		exit;
	}

	$existing = new DMMModule($db);
	if ($existing->fetch(0, $moduleId) > 0) {
		setEventMessages($langs->trans('DMMModuleAlreadyRegistered', $moduleId), null, 'warnings');
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $existing->id));
		exit;
	}

	$lang = substr($langs->defaultlang, 0, 2);
	$label = $entry['label'] ?? '';
	if (is_array($label)) {
		$label = $label[$lang] ?? ($label['en'] ?? reset($label));
	}
	$desc = $entry['description'] ?? '';
	if (is_array($desc)) {
		$desc = $desc[$lang] ?? ($desc['en'] ?? reset($desc));
	}
	$branch = trim((string) ($entry['git-branch'] ?? ''));

	$mod = new DMMModule($db);
	$mod->module_id = $moduleId;
	$mod->github_repo = $parsed['project'];
	$mod->git_host = $parsed['host'];
	$mod->git_base_url = $parsed['base_url'];
	$mod->subdir = $parsed['subdir'];
	$mod->source = 'dolibarr-community';
	$mod->name = (string) $label;
	$mod->description = (string) $desc;
	$mod->author = (string) ($entry['author'] ?? '');
	$mod->url = (string) ($entry['author_url'] ?? $entry['git']);
	if ($branch !== '') {
		// Branch-tracked: the channel selector reads branch_dev, and checkUpdate()
		// follows branch HEAD instead of hunting for tags.
		$mod->branch = $branch;
		$mod->branch_dev = $branch;
		$mod->channel = 'dev';
	}

	$installedVersion = $dmmClient->getInstalledVersion($moduleId);
	if ($installedVersion !== null || is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$moduleId)) {
		$mod->installed = 1;
		$mod->installed_version = $installedVersion;
	}

	$created = $mod->create($user);
	if ($created > 0) {
		if (!empty($entry['current_version'])) {
			$mod->updateCache(array(
				'latest_version' => (string) $entry['current_version'],
				'latest_compatible' => (string) $entry['current_version'],
			));
		}
		setEventMessages($langs->trans('DMMRepoAdded', $parsed['project']), null, 'mesgs');
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $created));
		exit;
	}
	setEventMessages($mod->error ?: 'create failed', null, 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?catalog=community');
	exit;
}

// Register a single module advertised by a hub. Same path as adding a repo by
// hand — a hub entry is just a repo someone else typed for you.
if ($action == 'addhubmodule' && dmm_user_can('write')) {
	$repo = trim((string) GETPOST('repo', 'alphanohtml'));
	$parsed = $repo !== '' ? $dmmClient->parsePublicRepoInput($repo) : null;
	if ($parsed === null) {
		setEventMessages($langs->trans('DMMErrorRepoFormat'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=hub');
		exit;
	}

	$manifest = array();
	if ($parsed['host'] === 'github') {
		$manifest = $dmmClient->fetchManifest($parsed['owner'], $parsed['repo'], null);
		if (!is_array($manifest)) {
			$manifest = array();
		}
	}
	$moduleId = $manifest['module_id'] ?? strtolower(preg_replace('/[^a-z0-9_]/i', '', $parsed['repo']));

	$existing = new DMMModule($db);
	if ($existing->fetch(0, $moduleId) > 0) {
		setEventMessages($langs->trans('DMMModuleAlreadyRegistered', $moduleId), null, 'warnings');
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $existing->id));
		exit;
	}

	$mod = new DMMModule($db);
	$mod->module_id = $moduleId;
	$mod->github_repo = $parsed['project'];
	$mod->git_host = $parsed['host'];
	$mod->git_base_url = $parsed['base_url'];
	$mod->subdir = $parsed['subdir'];
	$mod->source = 'hub';
	$mod->name = $manifest['name'] ?? null;
	$mod->description = $manifest['description'] ?? null;
	$mod->author = $manifest['author'] ?? null;
	$mod->license = $manifest['license'] ?? null;
	$mod->url = $manifest['url'] ?? null;

	// Already unpacked under custom/? Then it is installed, and the descriptor
	// knows which version.
	$installedVersion = $dmmClient->getInstalledVersion($moduleId);
	if ($installedVersion !== null || is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$moduleId)) {
		$mod->installed = 1;
		$mod->installed_version = $installedVersion;
	}

	$created = $mod->create($user);
	if ($created > 0) {
		setEventMessages($langs->trans('DMMRepoAdded', $parsed['project']), null, 'mesgs');
		header('Location: '.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.((int) $created));
		exit;
	}
	setEventMessages($mod->error ?: 'create failed', null, 'errors');
	header('Location: '.$_SERVER['PHP_SELF'].'?catalog=hub');
	exit;
}

// Re-read every configured source and register what it advertises: token
// discovery, the enabled hubs, the community list. This is what fills the
// catalogs below, so it belongs here — it used to sit above the installed
// modules list, bundled with an update check that had nothing to do with it.
if ($action == 'refreshsources' && dmm_user_can('write')) {
	dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
	$discovered = 0;

	$dmmToken = new DMMToken($db);
	foreach ($dmmToken->fetchAll(1) as $t) {
		$discovery = $dmmClient->discoverModules($t->id, $t->getDecryptedToken());
		$discovered += (int) ($discovery['discovered'] ?? 0);
	}

	foreach (dmm_get_hubs() as $hub) {
		if (!empty($hub['enabled'])) {
			$report = $dmmClient->importFromHub($hub['url']);
			$discovered += (int) ($report['registered'] ?? 0);
		}
	}

	$communityCfg = dmm_get_community_yaml_config();
	if (!empty($communityCfg['enabled'])) {
		$entries = $dmmClient->fetchCommunityYaml($communityCfg['url']);
		if (is_array($entries)) {
			$communityReport = $dmmClient->importFromCommunityYaml($entries);
			$discovered += (int) ($communityReport['registered'] ?? 0);
		} elseif (!empty($dmmClient->error)) {
			setEventMessages($dmmClient->error, null, 'warnings');
		}
	}

	setEventMessages($langs->trans('DMMNewModulesRegistered', $discovered), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?catalog='.$catalogSource);
	exit;
}

// Refresh the cached purchase list.
if ($action == 'refreshpurchases' && dmm_user_can('read')) {
	$baseTemp = isset($conf->dolimodulemanager->dir_temp)
		? $conf->dolimodulemanager->dir_temp
		: DOL_DATA_ROOT.'/dolimodulemanager/temp';
	@unlink($baseTemp.'/dolistore_purchases.json');
	header('Location: '.$_SERVER['PHP_SELF'].'?catalog=purchases');
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
		header('Location: '.$_SERVER['PHP_SELF'].'?catalog=purchases');
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
			header('Location: '.$_SERVER['PHP_SELF'].'?catalog=purchases');
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
	header('Location: '.$_SERVER['PHP_SELF'].'?catalog=purchases');
	exit;
}

/**
 * Render the Dolibarr community catalog (index.yaml from the foundation's
 * dolibarr-community-modules repo). First tab because it is the official,
 * curated list — where e-invoicing modules such as KSeF live.
 *
 * Unlike the other three, entries here carry a branch (git-branch) rather than
 * tagged releases: several are subdirectories of one shared repo tracking main.
 * That is what the branch column shows, and what gets stored so update checks
 * follow the branch instead of looking for tags that do not exist.
 *
 * @param  DoliDB    $db       Database handle
 * @param  DMMClient $client   Client (fetchCommunityYaml)
 * @param  Translate $langs    Language object
 * @param  string    $self     PHP_SELF for links
 * @param  string    $searchKw Keyword filter, '' for none
 * @return void
 */
function dmm_add_render_community_catalog($db, $client, $langs, $self, $searchKw)
{
	$cfg = dmm_get_community_yaml_config();

	print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromCommunityHelp').'</div>';

	$entries = $client->fetchCommunityYaml($cfg['url']);
	if (!is_array($entries)) {
		print '<div class="paddingtop">'.info_admin($client->error ?: $langs->trans('DMMCommunityFetchFailed'), 0, 0, 'warning').'</div>';
		return;
	}

	// Which of them does DMM already track? Match on the repo path, and let the
	// disk decide "installed" — the registry flag lags behind an unpacked module.
	$onDisk = $client->listInstalledOnDisk();
	$known = array();
	$sql = "SELECT module_id, github_repo FROM ".MAIN_DB_PREFIX."dmm_module WHERE github_repo IS NOT NULL";
	$res = $db->query($sql);
	if ($res) {
		while ($o = $db->fetch_object($res)) {
			$known[strtolower($o->github_repo)] = isset($onDisk[$o->module_id]) ? 1 : 0;
		}
	}

	$lang = substr($langs->defaultlang, 0, 2);
	$rows = array();
	foreach ($entries as $e) {
		$label = $e['label'] ?? '';
		if (is_array($label)) {
			$label = $label[$lang] ?? ($label['en'] ?? reset($label));
		}
		$desc = $e['description'] ?? '';
		if (is_array($desc)) {
			$desc = $desc[$lang] ?? ($desc['en'] ?? reset($desc));
		}
		$rows[] = array(
			'module_id' => (string) ($e['modulename'] ?? ''),
			'label' => (string) $label,
			'description' => (string) $desc,
			'git' => (string) ($e['git'] ?? ''),
			'branch' => (string) ($e['git-branch'] ?? ''),
			'version' => (string) ($e['current_version'] ?? ''),
			'dmin' => (string) ($e['dolibarrmin'] ?? ''),
			'dmax' => (string) ($e['dolibarrmax'] ?? ''),
			'author' => (string) ($e['author'] ?? ''),
			'status' => strtolower(trim((string) ($e['status'] ?? 'enabled'))),
		);
	}

	if ($searchKw !== '') {
		$needle = strtolower($searchKw);
		$rows = array_values(array_filter($rows, function ($r) use ($needle) {
			return strpos(strtolower($r['module_id'].' '.$r['label'].' '.$r['description']), $needle) !== false;
		}));
	}

	print '<form method="GET" action="'.$self.'" class="paddingtop">';
	print '<input type="hidden" name="catalog" value="community">';
	print '<input type="text" name="search" value="'.dol_escape_htmltag($searchKw).'" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('DMMSearchCatalog')).'">';
	print ' <input type="submit" class="button button-save small" value="'.$langs->trans('Search').'">';
	if ($searchKw !== '') {
		print ' <a class="butAction butActionSmall" href="'.$self.'?catalog=community">'.$langs->trans('Reset').'</a>';
	}
	print '</form>';

	print '<div class="opacitymedium small paddingtop">'.$langs->trans('DMMCatalogCount', count($rows)).'</div>';

	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th class="width150">'.$langs->trans('Module').'</th>';
	print '<th>'.$langs->trans('Description').'</th>';
	print '<th class="center width100">'.$langs->trans('DMMBranch').'</th>';
	print '<th class="center width100">'.$langs->trans('Version').'</th>';
	print '<th class="center width150">'.$langs->trans('Compatibility').'</th>';
	print '<th class="center width200">'.$langs->trans('Action').'</th>';
	print '</tr>';

	if (empty($rows)) {
		print '<tr><td colspan="6" class="center opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	foreach ($rows as $r) {
		// The git URL is either a plain repo or a /tree/<branch>/<subdir> pointer
		// into the shared community repo; parsePublicRepoInput() handles both.
		$parsed = $r['git'] !== '' ? $client->parsePublicRepoInput($r['git']) : null;
		$repoPath = $parsed !== null ? $parsed['project'] : '';
		$isKnown = $repoPath !== '' && isset($known[strtolower($repoPath)]);
		$isInstalled = $isKnown && $known[strtolower($repoPath)];

		print '<tr class="oddeven">';
		print '<td><strong>'.dolPrintHTML($r['label'] !== '' ? $r['label'] : $r['module_id']).'</strong>';
		if ($r['author'] !== '') {
			print '<br><small class="opacitymedium">'.dol_escape_htmltag($r['author']).'</small>';
		}
		print '</td>';
		print '<td><span class="small opacitymedium">'.dolPrintHTML(dol_trunc(dol_string_nohtmltag($r['description']), 160)).'</span></td>';
		print '<td class="center"><small class="opacitymedium">'.dol_escape_htmltag($r['branch'] ?: '-').'</small></td>';
		print '<td class="center">'.dol_escape_htmltag($r['version'] ?: '-').'</td>';
		print '<td class="center"><small class="opacitymedium">'.dol_escape_htmltag(($r['dmin'] ?: '?').' → '.($r['dmax'] ?: '?')).'</small></td>';

		print '<td class="center nowraponall dmm-cat-actions">';
		print '<span class="dmm-cat-slot">';
		if ($r['git'] !== '') {
			print '<a href="'.dol_escape_htmltag($r['git']).'" target="_blank" rel="noopener noreferrer" class="butAction butActionSmall" title="'.$langs->trans('View').'">'.img_picto('', 'url').'</a>';
		}
		print '</span>';
		print '<span class="dmm-cat-slot">';
		if ($isInstalled) {
			print '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>';
		} elseif ($isKnown) {
			print '<span class="opacitymedium small">'.$langs->trans('DMMAlreadyTracked').'</span>';
		} elseif ($parsed !== null && dmm_user_can('write')) {
			print '<a href="'.$self.'?action=addcommunity&mid='.urlencode($r['module_id']).'&token='.newToken().'" class="butAction butActionSmall">'.$langs->trans('Add').'</a>';
		}
		print '</span>';
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';
}

/**
 * Render the hub catalog: every module the enabled hubs advertise, in the same
 * shape as the DoliStore listing so switching between the two is just a switch.
 *
 * Hub JSON is small (tens of entries) and fetchHub() caches per hub, so unlike
 * the ~1700-product DoliStore catalog this can be read inline.
 *
 * @param  DoliDB    $db        Database handle
 * @param  DMMClient $client    Client (fetchHub)
 * @param  Translate $langs     Language object
 * @param  string    $self      PHP_SELF for links
 * @param  string    $searchKw  Keyword filter, '' for none
 * @param  int       $page      1-based page number
 * @param  int       $perPage   Rows per page
 * @return void
 */
function dmm_add_render_hub_catalog($db, $client, $langs, $self, $searchKw, $page, $perPage)
{
	$hubs = function_exists('dmm_get_hubs') ? dmm_get_hubs() : array();
	$enabled = array();
	foreach ($hubs as $h) {
		if (!empty($h['enabled'])) {
			$enabled[] = $h;
		}
	}

	print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromHubHelp').'</div>';

	if (empty($enabled)) {
		print '<div class="paddingtop opacitymedium">'.$langs->trans('DMMNoHubEnabled').'</div>';
		// Sources is developer-only; pointing there with developer mode off would
		// bounce the user straight back to Settings. Say what to turn on instead.
		if (dmm_is_dev_mode()) {
			print '<div class="paddingtop"><a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/sources.php', 1).'">'.$langs->trans('DMMSourcesTab').'</a></div>';
		} else {
			print '<div class="paddingtop opacitymedium small">'.$langs->trans('DMMHubsNeedDevMode').'</div>';
			print '<div class="paddingtop"><a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'">'.$langs->trans('DMMSettingsTab').'</a></div>';
		}
		return;
	}

	// Flatten every hub into one list, first hub wins on duplicate repos.
	$entries = array();
	$errors = array();
	foreach ($enabled as $h) {
		$data = $client->fetchHub($h['url']);
		if (!is_array($data) || empty($data['modules'])) {
			$errors[] = $h['url'];
			continue;
		}
		$hubName = $data['name'] ?? (parse_url($h['url'], PHP_URL_HOST) ?: $h['url']);
		foreach ($data['modules'] as $m) {
			if (empty($m['repo']) || isset($entries[$m['repo']])) {
				continue;
			}
			$m['_hub'] = $hubName;
			$entries[$m['repo']] = $m;
		}
	}
	$entries = array_values($entries);

	if (!empty($errors)) {
		print '<div class="paddingtop"><span class="opacitymedium small">'.$langs->trans('DMMHubFetchFailed', implode(', ', $errors)).'</span></div>';
	}

	// Which repos are already in the registry, and which of those are really on
	// disk? The registry's installed flag lags — a row imported from a hub keeps
	// installed=0 even once its files are there — so let the disk decide, the same
	// way the installed-modules list does.
	$onDisk = $client->listInstalledOnDisk();
	$known = array();
	$sql = "SELECT module_id, github_repo FROM ".MAIN_DB_PREFIX."dmm_module WHERE github_repo IS NOT NULL";
	$res = $db->query($sql);
	if ($res) {
		while ($o = $db->fetch_object($res)) {
			$known[strtolower($o->github_repo)] = isset($onDisk[$o->module_id]) ? 1 : 0;
		}
	}

	if ($searchKw !== '') {
		$needle = strtolower($searchKw);
		$entries = array_values(array_filter($entries, function ($m) use ($needle) {
			return strpos(strtolower(($m['name'] ?? '').' '.($m['description'] ?? '').' '.($m['repo'] ?? '')), $needle) !== false;
		}));
	}

	$total = count($entries);
	$pageCount = max(1, (int) ceil($total / $perPage));
	$page = min(max(1, $page), $pageCount);
	$slice = array_slice($entries, ($page - 1) * $perPage, $perPage);

	print '<form method="GET" action="'.$self.'" class="paddingtop">';
	print '<input type="hidden" name="catalog" value="hub">';
	print '<input type="text" name="search" value="'.dol_escape_htmltag($searchKw).'" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('DMMSearchCatalog')).'">';
	print ' <input type="submit" class="button button-save small" value="'.$langs->trans('Search').'">';
	if ($searchKw !== '') {
		print ' <a class="butAction butActionSmall" href="'.$self.'?catalog=hub">'.$langs->trans('Reset').'</a>';
	}
	print '</form>';

	print '<div class="opacitymedium small paddingtop">'.$langs->trans('DMMCatalogCount', $total).'</div>';

	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th class="width150">'.$langs->trans('Module').'</th>';
	print '<th>'.$langs->trans('Description').'</th>';
	print '<th class="center width120">'.$langs->trans('DMMSource').'</th>';
	print '<th class="center width200">'.$langs->trans('Action').'</th>';
	print '</tr>';

	if (empty($slice)) {
		print '<tr><td colspan="4" class="center opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	foreach ($slice as $m) {
		$repo = (string) $m['repo'];
		$isKnown = isset($known[strtolower($repo)]);
		$isInstalled = $isKnown && $known[strtolower($repo)];
		$isPublic = !isset($m['public']) || !empty($m['public']);

		print '<tr class="oddeven">';
		print '<td><strong>'.dolPrintHTML($m['name'] ?? $repo).'</strong><br>';
		print '<small class="opacitymedium">'.dol_escape_htmltag($repo).'</small></td>';
		print '<td><span class="small opacitymedium">'.dolPrintHTML(dol_string_nohtmltag($m['description'] ?? '')).'</span></td>';
		print '<td class="center"><small class="opacitymedium">'.dol_escape_htmltag($m['_hub']).'</small>';
		if (!$isPublic) {
			print '<br><span class="badge badge-warning">'.$langs->trans('DMMPrivate').'</span>';
		}
		print '</td>';

		print '<td class="center nowraponall dmm-cat-actions">';
		print '<span class="dmm-cat-slot"><a href="https://github.com/'.dol_escape_htmltag($repo).'" target="_blank" rel="noopener noreferrer" class="butAction butActionSmall" title="'.$langs->trans('View').'">'.img_picto('', 'url').'</a></span>';
		print '<span class="dmm-cat-slot">';
		if ($isInstalled) {
			print '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>';
		} elseif ($isKnown) {
			print '<span class="opacitymedium small">'.$langs->trans('DMMAlreadyTracked').'</span>';
		} elseif (dmm_user_can('write')) {
			print '<a href="'.$self.'?action=addhubmodule&repo='.urlencode($repo).'&token='.newToken().'" class="butAction butActionSmall">'.$langs->trans('Add').'</a>';
		}
		print '</span>';
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';

	if ($pageCount > 1) {
		$qs = '&catalog=hub'.($searchKw !== '' ? '&search='.urlencode($searchKw) : '');
		print '<div class="center paddingtop">';
		if ($page > 1) {
			print '<a class="butAction butActionSmall" href="'.$self.'?page='.($page - 1).$qs.'">&laquo;</a> ';
		}
		print '<span class="opacitymedium small">'.$langs->trans('Page').' '.$page.' / '.$pageCount.'</span>';
		if ($page < $pageCount) {
			print ' <a class="butAction butActionSmall" href="'.$self.'?page='.($page + 1).$qs.'">&raquo;</a>';
		}
		print '</div>';
	}
}

/**
 * Render the purchases catalog: modules this DoliStore account owns.
 *
 * Third view of the same switch — a directory of installable modules, like the
 * DoliStore catalog and the hubs, but scoped to what has been bought. It is a
 * separate view rather than a filter because it needs credentials and answers a
 * different question ("what did I pay for" vs "what exists").
 *
 * @param  DoliDB    $db    Database handle
 * @param  Translate $langs Language object
 * @param  string    $self  PHP_SELF for links
 * @return void
 */
function dmm_add_render_purchases($db, $langs, $self)
{
	dol_include_once('/dolimodulemanager/class/DMMDolistoreSession.class.php');
	dol_include_once('/dolimodulemanager/class/DMMClient.class.php');

	$session = new DMMDolistoreSession($db);
	if (!$session->hasCredentials()) {
		print '<div class="paddingtop opacitymedium">'.$langs->trans('DMMConfigureDolistoreCreds').'</div>';
		print '<div class="paddingtop"><a href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'#dolistore" class="butAction">'.$langs->trans('DMMOpenSetup').'</a></div>';
		return;
	}

	$purchases = dmm_scan_load_purchases($db);
	print '<div class="tabsAction tabsActionNoBottom">';
	print '<a href="'.$self.'?catalog=purchases&action=refreshpurchases&token='.newToken().'" class="butAction">'.img_picto('', 'refresh', 'class="paddingright"').$langs->trans('DMMRefreshPurchases').'</a>';
	print '</div>';

	if (empty($purchases)) {
		print '<div class="opacitymedium">'.$langs->trans('DMMNoPurchasesFound').'</div>';
		return;
	}

	// Same disk-wins rule as the other catalogs: the registry's installed flag can
	// lag behind what is actually unpacked under custom/.
	$client = new DMMClient($db);
	$onDisk = $client->listInstalledOnDisk();
	$known = array();
	$sql = "SELECT module_id, dolistore_id FROM ".MAIN_DB_PREFIX."dmm_module WHERE dolistore_id IS NOT NULL";
	$res = $db->query($sql);
	if ($res) {
		while ($o = $db->fetch_object($res)) {
			$known[(int) $o->dolistore_id] = isset($onDisk[$o->module_id]) ? 1 : 0;
		}
	}

	print '<div class="opacitymedium small paddingtop">'.$langs->trans('DMMCatalogCount', count($purchases)).'</div>';

	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Module').'</th>';
	print '<th class="center width150">'.$langs->trans('Order').'</th>';
	print '<th class="center width120">'.$langs->trans('Status').'</th>';
	print '<th class="center width200">'.$langs->trans('Action').'</th>';
	print '</tr>';

	foreach ($purchases as $p) {
		$pid = (int) ($p['id'] ?? 0);
		$isKnown = isset($known[$pid]);
		$isInstalled = $isKnown && $known[$pid];

		print '<tr class="oddeven">';
		print '<td><strong>'.dolPrintHTML($p['name']).'</strong></td>';
		print '<td class="center"><small class="opacitymedium">'.dol_escape_htmltag($p['ref'] ?? '').'</small></td>';
		print '<td class="center">';
		print $isInstalled
			? '<span class="badge badge-status4">'.$langs->trans('Installed').'</span>'
			: '<span class="opacitymedium small">'.$langs->trans('NotInstalled').'</span>';
		print '</td>';

		print '<td class="center nowraponall dmm-cat-actions">';
		print '<span class="dmm-cat-slot">';
		if ($pid > 0) {
			print '<a href="'.DMMDolistoreSession::SHOP_URL.'/product.php?id='.$pid.'" target="_blank" rel="noopener noreferrer" class="butAction butActionSmall" title="'.$langs->trans('View').'">'.img_picto('', 'url').'</a>';
		}
		print '</span>';
		print '<span class="dmm-cat-slot">';
		if (!empty($p['zip_url']) && $pid > 0 && dmm_user_can('write')) {
			$wh = md5($p['zip_url']);
			$label = $isInstalled ? $langs->trans('Update') : $langs->trans('Install');
			print '<a href="'.$self.'?action=installpurchase&dolistore_id='.$pid.'&wh='.$wh.'&token='.newToken().'" class="butAction butActionSmall">'.$label.'</a>';
		} else {
			print '<span class="opacitymedium small">'.$langs->trans('DMMNoDownloadAvailable').'</span>';
		}
		print '</span>';
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';
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
/* Same as the dashboard: the action bar is sized for a card footer. See the
   tabsActionNoBottom class on the div for the vertical half. */
.page-admin-add .tabsAction { margin-top: 8px; margin-bottom: 8px; }
.page-admin-add div.tabsAction > a.butAction { margin-left: 0; margin-right: 8px !important; }
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

// Hubs are browsable below, next to the DoliStore catalog — no entry needed here.

print '</div>'; // .dmm-add-bar
print '<div class="clearboth"></div>';

// Re-reads tokens, hubs and the community list in one go — the sources that feed
// the catalogs below.
if (dmm_user_can('write')) {
	print '<div class="tabsAction tabsActionNoBottom">';
	print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMRefreshSources')).' href="'.$_SERVER['PHP_SELF'].'?action=refreshsources&catalog='.$catalogSource.'&token='.newToken().'">'.img_picto('', 'fa-sync', 'class="pictofixedwidth"').$langs->trans('DMMRefreshSources').'</a>';
	print '</div>';
}
print '<br>';

// ---- Browsable catalogs ----
// Four directories of modules to install from, so one switch between them rather
// than one section each: the question "where do I look" is asked once.
print '<div class="fichecenter">';

print '<div class="tabs" data-role="controlgroup" data-type="horizontal">';
foreach (array('community' => 'DMMAddFromCommunity', 'dolistore' => 'DMMAddFromDolistore', 'hub' => 'DMMAddFromHub', 'purchases' => 'DMMPurchases') as $srcKey => $srcLabel) {
	$active = ($catalogSource === $srcKey) ? ' inline-block tabactive' : ' inline-block';
	print '<div class="'.$active.'"><a class="tab" href="'.$_SERVER['PHP_SELF'].'?catalog='.$srcKey.'">'.$langs->trans($srcLabel).'</a></div>';
}
print '</div><div class="clearboth"></div>';

if ($catalogSource === 'community') {
	dmm_add_render_community_catalog($db, $dmmClient, $langs, $_SERVER['PHP_SELF'], $searchKw);
	print '</div><br>';
} elseif ($catalogSource === 'hub') {
	dmm_add_render_hub_catalog($db, $dmmClient, $langs, $_SERVER['PHP_SELF'], $searchKw, $page, $perPage);
	print '</div><br>';
} elseif ($catalogSource === 'purchases') {
	dmm_add_render_purchases($db, $langs, $_SERVER['PHP_SELF']);
	print '</div><br>';
} else {

$dsCatalog = new DMMDolistoreClient($langs->defaultlang);
if (!$dsCatalog->isCatalogCached()) {
	// ~1700 products is seconds of network: never inline, or the page hangs on it.
	// A drawer that fetches on click keeps the rest of the page usable meanwhile,
	// and once cached the catalog stays for 24h so this is a one-off.
	print '<div class="opacitymedium small">'.$langs->trans('DMMAddFromDolistoreHelp').'</div>';
	print '<div class="paddingtop opacitymedium" id="dmmCatalogIdle">'.$langs->trans('DMMCatalogNotLoaded').'</div>';
	if (dmm_user_can('write')) {
		$warmUrl = $_SERVER['PHP_SELF'].'?action=loadcatalog&token='.newToken();
		print '<div class="paddingtop"><a class="butAction" id="dmmLoadCatalog" href="'.$warmUrl.'">'.$langs->trans('DMMLoadCatalog').'</a></div>';
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
			window.location.href = '.json_encode($_SERVER['PHP_SELF'].'').';
		}).catch(function () {
			window.location.href = '.json_encode($_SERVER['PHP_SELF'].'').';
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
		print ' <a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans('Reset').'</a>';
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
			print '<a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'?page='.($page - 1).$qs.'">&laquo;</a> ';
		}
		print '<span class="opacitymedium small">'.$langs->trans('Page').' '.$page.' / '.$pageCount.'</span>';
		if ($page < $pageCount) {
			print ' <a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'?page='.($page + 1).$qs.'">&raquo;</a>';
		}
		print '</div>';
	}
}
print '</div><br>';
} // end catalog source switch


print dol_get_fiche_end();

llxFooter();
$db->close();
