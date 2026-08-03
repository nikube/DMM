<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/index.php
 * \ingroup dolimodulemanager
 * \brief   Dashboard — module catalog, updates, add repo
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
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

dmm_require_right('read');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
// Default to "all": this screen lists what is installed on this Dolibarr, disk
// included, so "everything" is already the right scope — filtering to registry
// rows would hide the very modules DMM does not know about yet.
$filter = GETPOST('filter', 'alpha') ?: 'all';
$isAjax = dmm_is_ajax_request();

$dmmModule = new DMMModule($db);
$dmmToken = new DMMToken($db);
$dmmClient = new DMMClient($db);
$form = new Form($db);

/*
 * Actions
 */

// Remove module from registry
if ($action == 'confirm_removemodule' && $id > 0 && dmm_user_can('write')) {
	$mod = new DMMModule($db);
	$mod->fetch($id);
	$mod->delete($user);
	setEventMessages($langs->trans('DMMModuleRemovedFromRegistry'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

// Check for updates (single module)
if ($action == 'checkupdate' && $id > 0) {
	$mod = new DMMModule($db);
	$mod->fetch($id);
	$plainToken = null;
	if (!empty($mod->fk_dmm_token)) {
		$tokenObj = new DMMToken($db);
		if ($tokenObj->fetch($mod->fk_dmm_token) > 0) {
			$plainToken = $tokenObj->getDecryptedToken();
		}
	}
	$result = $dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
	$redirectUrl = $_SERVER['PHP_SELF'].'?filter='.$filter;
	if ($isAjax) {
		dmm_ajax_response(array(
			'success' => ($result !== null),
			'redirect' => $redirectUrl,
			'logs' => array($langs->trans('DMMLogCheckedModule', $mod->module_id)),
			'results' => array(
				$mod->module_id => array(
					'ok' => ($result !== null),
					'error' => ($result === null ? $dmmClient->error : ''),
				),
			),
		));
	}
	if ($result === null && !empty($dmmClient->error)) {
		setEventMessages($dmmClient->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('DMMCheckComplete'), null, 'mesgs');
	}
	header('Location: '.$redirectUrl);
	exit;
}

// Refresh all sources: discover from all tokens + refresh all hubs + check all modules
if ($action == 'refreshsources' && dmm_user_can('write')) {
	$totalDiscovered = 0;
	$communityReport = null;

	// Discover from all active tokens
	$allTokens = $dmmToken->fetchAll(1);
	foreach ($allTokens as $t) {
		$discovery = $dmmClient->discoverModules($t->id, $t->getDecryptedToken());
		$totalDiscovered += $discovery['discovered'];
	}

	// Refresh all enabled hubs
	$hubs = dmm_get_hubs();
	foreach ($hubs as $hub) {
		if (!empty($hub['enabled'])) {
			$dmmClient->importFromHub($hub['url']);
		}
	}

	// Pull Dolibarr community YAML when enabled
	$communityCfg = dmm_get_community_yaml_config();
	if ($communityCfg['enabled']) {
		$entries = $dmmClient->fetchCommunityYaml($communityCfg['url']);
		if (is_array($entries)) {
			$communityReport = $dmmClient->importFromCommunityYaml($entries);
			$totalDiscovered += (int) ($communityReport['registered'] ?? 0);
		} elseif (!empty($dmmClient->error)) {
			setEventMessages($dmmClient->error, null, 'warnings');
		}
	}

	// Check all modules for updates
	$allMods = $dmmModule->fetchAll();
	$errors = array();
	$ajaxLogs = array();
	$ajaxResults = array();
	$rateLimited = false;
	foreach ($allMods as $mod) {
		$tokenObj = new DMMToken($db);
		if ($mod->fk_dmm_token) {
			$tokenObj->fetch($mod->fk_dmm_token);
		}
		$result = $dmmClient->checkUpdate($mod->module_id, $mod->fk_dmm_token ? $tokenObj->getDecryptedToken() : null, $mod->github_repo);
		$ajaxLogs[] = $langs->trans('DMMLogCheckedModule', $mod->module_id);
		$ajaxResults[$mod->module_id] = array('ok' => ($result !== null), 'error' => '');
		if ($result === null && !empty($dmmClient->error)) {
			$ajaxResults[$mod->module_id]['error'] = $dmmClient->error;
			if (strpos($dmmClient->error, 'rate limit') !== false) {
				$rateLimited = true;
				break; // Stop checking, no point hitting the API more
			}
			$errors[] = $mod->module_id.': '.$dmmClient->error;
			$ajaxLogs[] = $langs->trans('DMMLogModuleError', $mod->module_id, $dmmClient->error);
		}
	}

	$msg = $langs->trans('DMMSourcesRefreshed', count($allTokens), count($hubs), count($allMods));
	if ($totalDiscovered > 0) {
		$msg .= ' | '.$langs->trans('DMMNewModulesRegistered', $totalDiscovered);
	}
	setEventMessages($msg, null, 'mesgs');
	if (is_array($communityReport)) {
		// Note: Dolibarr's trans() caps substitution args at 4. Combine registered+updated
		// into a single "saved" count so the summary stays readable within that limit.
		$saved = (int) ($communityReport['registered'] ?? 0) + (int) ($communityReport['updated'] ?? 0);
		setEventMessages($langs->trans('DMMCommunityImportReport', $communityReport['total'], $saved, $communityReport['skipped'], $communityReport['monorepo']), null, 'mesgs');
	}
	if ($rateLimited) {
		setEventMessages($dmmClient->error, null, 'errors');
	} elseif (!empty($errors)) {
		setEventMessages(implode(' | ', array_slice($errors, 0, 3)), null, 'warnings');
	}
	$redirectUrl = $_SERVER['PHP_SELF'].'?filter='.$filter;
	if ($isAjax) {
		dmm_ajax_response(array('success' => !$rateLimited, 'redirect' => $redirectUrl, 'logs' => $ajaxLogs, 'results' => $ajaxResults));
	}
	header('Location: '.$redirectUrl);
	exit;
}

// Check modules
if ($action == 'checktargets') {
	$scope = GETPOST('scope', 'alpha');
	$targetMods = ($scope == 'installed') ? $dmmModule->fetchAll('installed') : $dmmModule->fetchAll();
	$targets = array();
	foreach ($targetMods as $mod) {
		$targets[] = array(
			'id' => (int) $mod->id,
			'module_id' => $mod->module_id,
			'url' => $_SERVER['PHP_SELF'].'?action=checkupdate&token='.newToken().'&id='.(int) $mod->id.'&filter='.$filter,
		);
	}
	if ($isAjax) {
		dmm_ajax_response(array('success' => true, 'targets' => $targets));
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

if ($action == 'checkbatchdone') {
	$checked = GETPOSTINT('checked');
	$failed = GETPOSTINT('failed');
	setEventMessages($langs->trans('DMMCheckedModules', $checked), null, 'mesgs');
	if ($failed > 0) {
		setEventMessages($langs->trans('DMMCheckBatchErrors', $failed), null, 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

if ($action == 'checkall' || $action == 'checkinstalled') {
	$allMods = ($action == 'checkinstalled') ? $dmmModule->fetchAll('installed') : $dmmModule->fetchAll();
	$errors = array();
	$ajaxLogs = array();
	$ajaxResults = array();
	$rateLimited = false;
	foreach ($allMods as $mod) {
		$tokenObj = new DMMToken($db);
		if ($mod->fk_dmm_token) {
			$tokenObj->fetch($mod->fk_dmm_token);
		}
		$result = $dmmClient->checkUpdate($mod->module_id, $mod->fk_dmm_token ? $tokenObj->getDecryptedToken() : null, $mod->github_repo);
		$ajaxLogs[] = $langs->trans('DMMLogCheckedModule', $mod->module_id);
		$ajaxResults[$mod->module_id] = array('ok' => ($result !== null), 'error' => '');
		if ($result === null && !empty($dmmClient->error)) {
			$ajaxResults[$mod->module_id]['error'] = $dmmClient->error;
			if (strpos($dmmClient->error, 'rate limit') !== false) {
				$rateLimited = true;
				break;
			}
			$errors[] = $mod->module_id.': '.$dmmClient->error;
			$ajaxLogs[] = $langs->trans('DMMLogModuleError', $mod->module_id, $dmmClient->error);
		}
	}
	setEventMessages($langs->trans('DMMCheckedModules', count($allMods)), null, 'mesgs');
	if ($rateLimited) {
		setEventMessages($dmmClient->error, null, 'errors');
	} elseif (!empty($errors)) {
		setEventMessages(implode(' | ', array_slice($errors, 0, 3)), null, 'warnings');
	}
	$redirectUrl = $_SERVER['PHP_SELF'].'?filter='.$filter;
	if ($isAjax) {
		dmm_ajax_response(array('success' => !$rateLimited, 'redirect' => $redirectUrl, 'logs' => $ajaxLogs, 'results' => $ajaxResults));
	}
	header('Location: '.$redirectUrl);
	exit;
}

// ---- First-run: import default hub(s) once, then redirect to preflight ----
// Done here (first dashboard load) rather than in the module init() so a slow or
// unreachable hub can never block module activation. Runs a single time, guarded
// by its own flag, so a fresh install lands on a populated catalog (DMM itself
// included, since the default hub lists nikube/DMM).
if (dmm_get_setting('hub_autoimport_done', '0') !== '1') {
	dmm_set_setting('hub_autoimport_done', '1');
	@set_time_limit(120);
	foreach (dmm_get_hubs() as $hub) {
		if (!empty($hub['enabled'])) {
			$dmmClient->importFromHub($hub['url']);
		}
	}
}

// MUST happen before llxHeader() or any print — header() can't run after output.
$firstRun = dmm_get_setting('first_run_done', '0');
if ($firstRun !== '1') {
	dmm_set_setting('first_run_done', '1');
	$preflightUrl = dol_buildpath('/dolimodulemanager/dmm_preflight_web.php', 1);
	header('Location: '.$preflightUrl);
	exit;
}

/*
 * View
 */

$title = $langs->trans('DMMDashboard');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-admin-index');
dmm_print_ajax_loader_assets();

// Fixed-width slots for the per-row action icons, so a row missing an optional
// action still lines its remaining icons up with the rows around it.
print '<style>
.dmm-action-cell { white-space: nowrap; }
.dmm-action-slot {
	display: inline-block;
	width: 24px;
	text-align: center;
}
.dmm-action-slot + .dmm-action-slot { margin-left: 4px; }
.dmm-action-slot .pictofixedwidth { padding-right: 0; }
</style>';

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('DoliModuleManager'), $linkback, 'title_setup');

$head = dolimodulemanagerAdminPrepareHead();
print dol_get_fiche_head($head, 'dashboard', $langs->trans('DoliModuleManager'), -1, 'fa-cubes');

// ---- Permission check banner ----
$customDir = DOL_DOCUMENT_ROOT.'/custom';
$phpUser = dmm_get_php_user();
$permProblems = array();
if (is_dir($customDir)) {
	$dirs = array_filter(scandir($customDir), function ($d) use ($customDir) {
		return $d[0] !== '.' && is_dir($customDir.'/'.$d);
	});
	foreach ($dirs as $d) {
		$path = $customDir.'/'.$d;
		// Check dir itself AND sample files inside
		$hasIssue = false;
		if (!is_writable($path)) {
			$hasIssue = true;
		} else {
			$files = @glob($path.'/*');
			foreach (array_slice($files ?: array(), 0, 5) as $f) {
				if (!is_writable($f)) {
					$hasIssue = true;
					break;
				}
			}
		}
		if ($hasIssue) {
			$permProblems[] = $d;
		}
	}
}
if (!empty($permProblems)) {
	print '<div class="warning">';
	print img_picto('', 'fa-exclamation-triangle', 'class="pictofixedwidth"');
	print '<strong>'.$langs->trans('DMMPermissionWarning').'</strong><br>';
	print $langs->trans('DMMPermissionWarningDetail', implode(', ', $permProblems)).'<br>';
	print '<code>chown -R '.$phpUser.':'.$phpUser.' '.$customDir.'/ && chmod -R u+w '.$customDir.'/</code>';
	print '</div><br>';
}

// Load data
$allModules = $dmmModule->fetchAll();
$modulesWithUpdates = $dmmModule->fetchAll('updates');
$installedCount = 0;
foreach ($allModules as $m) {
	if ($m->installed) {
		$installedCount++;
	}
}

// ---- Summary boxes ----
print '<div class="fichecenter">';
print '<div class="fichethirdleft">';
print '<div class="info-box"><span class="info-box-icon bg-infobox-project">'.img_picto('', 'fa-puzzle-piece', 'class="fa-2x"').'</span>';
print '<div class="info-box-content"><span class="info-box-text">'.$langs->trans('DMMModulesManaged').'</span>';
print '<span class="info-box-number">'.count($allModules).' ('.$installedCount.' installed)</span></div></div>';
print '</div><div class="fichethirdleft">';
$updateClass = count($modulesWithUpdates) > 0 ? 'bg-infobox-action' : 'bg-infobox-project';
print '<div class="info-box"><span class="info-box-icon '.$updateClass.'">'.img_picto('', 'fa-arrow-circle-up', 'class="fa-2x"').'</span>';
print '<div class="info-box-content"><span class="info-box-text">'.$langs->trans('DMMUpdatesAvailable').'</span>';
print '<span class="info-box-number">'.count($modulesWithUpdates).'</span></div></div>';
print '</div></div>';
print '<div class="clearboth"></div>';

// ---- Action buttons ----
print '<div class="tabsAction">';
print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMRefreshSources')).' href="'.$_SERVER['PHP_SELF'].'?action=refreshsources&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMRefreshSources').'</a>';
print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMCheckAllNow')).' data-dmm-batch="module-checks" data-dmm-scope="all" href="'.$_SERVER['PHP_SELF'].'?action=checkall&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMCheckAllNow').'</a>';
print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMCheckInstalledNow')).' data-dmm-batch="module-checks" data-dmm-scope="installed" href="'.$_SERVER['PHP_SELF'].'?action=checkinstalled&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMCheckInstalledNow').'</a>';
print '</div>';

// ---- Filter tabs ----
print '<div class="tabs" data-role="controlgroup" data-type="horizontal">';
$filters = array('all' => 'DMMFilterAll', 'installed' => 'DMMFilterInstalled', 'updates' => 'DMMFilterUpdates', 'notinstalled' => 'DMMNotInstalled', 'unmanaged' => 'DMMUnmanaged');
foreach ($filters as $fkey => $flabel) {
	$active = ($filter === $fkey) ? ' inline-block tabactive' : ' inline-block';
	print '<div class="'.$active.'"><a class="tab" href="'.$_SERVER['PHP_SELF'].'?filter='.$fkey.'">'.$langs->trans($flabel).'</a></div>';
}
print '</div><div class="clearboth"></div>';

// ---- Module list ----
// The registry answers "what has DMM been told about"; the disk answers "what is
// actually installed here". They diverge badly in practice — a module dropped in
// by FTP, or installed before DMM existed, is invisible to the registry. List the
// union so this screen means "my modules" rather than "my DMM rows".
$modules = $dmmModule->fetchAll($filter);

$onDisk = $dmmClient->listInstalledOnDisk();
$knownIds = array();
foreach ($modules as $mod) {
	$knownIds[$mod->module_id] = true;
}
// A registry row flagged installed but whose directory is gone: nothing ever
// resets that flag, so surface it rather than silently lying. DMM itself is
// excluded from listInstalledOnDisk() by design, so exclude it here too — it is
// obviously present, being the code that is running.
foreach ($modules as $mod) {
	$mod->dmm_missing_files = ($mod->installed
		&& $mod->module_id !== 'dolimodulemanager'
		&& !dmm_is_core_module($mod->module_id)
		&& !isset($onDisk[$mod->module_id]));
}

// Unmanaged modules are synthesised as DMMModule-shaped rows so the rendering
// loop below stays a single code path.
$unmanaged = array();
if ($filter === 'all' || $filter === 'unmanaged') {
	// A row already in the registry is managed, whatever the active filter hid.
	$allRows = ($filter === 'all') ? $modules : $dmmModule->fetchAll('all');
	$registered = array();
	foreach ($allRows as $r) {
		$registered[$r->module_id] = true;
	}
	foreach ($onDisk as $mid => $info) {
		if (isset($registered[$mid])) {
			continue;
		}
		$ghost = new DMMModule($db);
		$ghost->id = 0;
		$ghost->module_id = $mid;
		$ghost->name = $mid;
		$ghost->installed = 1;
		$ghost->installed_version = $info['version'];
		$ghost->dmm_unmanaged = true;
		$unmanaged[] = $ghost;
	}
}
if ($filter === 'unmanaged') {
	$modules = $unmanaged;
} else {
	$modules = array_merge($modules, $unmanaged);
}

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DMMModuleId').'</td>';
print '<td class="tdoverflowmax150">'.$langs->trans('Name').'</td>';
print '<td class="tdoverflowmax200">'.$langs->trans('DMMSourceURL').'</td>';
print '<td class="center">'.dmm_label_help($langs->trans('DMMSource'), 'DMMSourceColumnTooltip', 'sources').'</td>';
print '<td class="center">'.$langs->trans('DMMInstalledVersion').'</td>';
print '<td class="center">'.$langs->trans('DMMCompatibleVersion').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td class="center">'.$langs->trans('Action').'</td>';
print '</tr>';

if (empty($modules)) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium">'.$langs->trans('DMMNoModules').'</td></tr>';
}

foreach ($modules as $mod) {
	print '<tr class="oddeven">';
	// An unmanaged module has no registry row, so no id to address module.php with
	// (it redirects on id<=0). Show the directory name plainly; the row's action
	// column is what offers a way in.
	print '<td class="tdoverflowmax100">';
	if (!empty($mod->dmm_unmanaged)) {
		print dol_escape_htmltag($mod->module_id);
	} else {
		print '<a href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'">'.dol_escape_htmltag($mod->module_id).'</a>';
	}
	print '</td>';
	print '<td class="tdoverflowmax150">'.dol_escape_htmltag($mod->name ?: '-').'</td>';
	// Repo / source URL: GitHub modules link to the repo, DoliStore modules link
	// to the product page on dolistore.com (the github_repo column carries the
	// "dolistore:NNN" placeholder which is not a clickable URL).
	print '<td class="tdoverflowmax200">';
	if (($mod->source ?? '') === 'dolistore' && !empty($mod->dolistore_id)) {
		$dsUrl = 'https://www.dolistore.com/product.php?id='.((int) $mod->dolistore_id);
		print '<a href="'.$dsUrl.'" target="_blank" rel="noopener">DoliStore #'.((int) $mod->dolistore_id).' '.img_picto('', 'fa-external-link-alt', 'class="paddingleft opacitymedium small"').'</a>';
	} else {
		// GitLab self-hosted modules link to their instance; GitHub modules to github.com.
		if (($mod->git_host ?? 'github') === 'gitlab' && !empty($mod->git_base_url)) {
			$repoUrl = rtrim($mod->git_base_url, '/').'/'.$mod->github_repo;
		} else {
			$repoUrl = 'https://github.com/'.$mod->github_repo;
		}
		print '<a href="'.dol_escape_htmltag($repoUrl).'" target="_blank" rel="noopener">'.dol_escape_htmltag($mod->github_repo).' '.img_picto('', 'fa-external-link-alt', 'class="paddingleft opacitymedium small"').'</a>';
	}
	print '</td>';

	// Source badge (token / hub / community / dolistore)
	print '<td class="center">';
	$src = $mod->source ?: ($mod->fk_dmm_token ? 'token' : 'hub');
	switch ($src) {
		case 'dolibarr-community':
			print '<span class="badge badge-info" title="Dolibarr community modules">'.$langs->trans('DMMSourceCommunity').'</span>';
			break;
		case 'dolistore':
			print '<span class="badge badge-warning" title="DoliStore">'.$langs->trans('DMMSourceDolistore').'</span>';
			break;
		case 'token':
			print '<span class="badge badge-status4">'.$langs->trans('DMMSourceToken').'</span>';
			break;
		case 'hub':
		default:
			print '<span class="badge badge-secondary">'.$langs->trans('DMMSourceHub').'</span>';
			break;
	}
	print '</td>';

	print '<td class="center">'.($mod->installed_version ?: '-').'</td>';
	print '<td class="center">'.($mod->cache_latest_compatible ?: '-').'</td>';

	// Status
	print '<td class="center nowraponall">';
	$isPrivateNoToken = (!$mod->installed && empty($mod->fk_dmm_token) && !empty($mod->cache_last_error) && strpos($mod->cache_last_error, 'No token') !== false);
	$upstreamStatus = (!empty($mod->cache_last_error) && strpos($mod->cache_last_error, 'upstream_status:') === 0)
		? substr($mod->cache_last_error, strlen('upstream_status:'))
		: null;
	if (!empty($mod->dmm_unmanaged)) {
		// On disk, but DMM knows nothing about it: no source, so no update checks and
		// no install path. The tooltip says what to do about it.
		print '<span class="badge badge-secondary" title="'.dol_escape_htmltag($langs->trans('DMMUnmanagedHelp')).'">'.$langs->trans('DMMUnmanaged').'</span>';
	} elseif (!empty($mod->dmm_missing_files)) {
		// Registry says installed, disk disagrees. Nothing resets the flag, so this
		// state persists until someone looks — say so instead of showing "up to date".
		print '<span class="badge badge-danger" title="'.dol_escape_htmltag($langs->trans('DMMMissingFilesHelp')).'">'.$langs->trans('DMMMissingFiles').'</span>';
	} elseif ($isPrivateNoToken) {
		// Private module without token — show "Private" badge with link if available
		print '<span class="badge badge-warning">'.$langs->trans('DMMPrivate').'</span>';
		if (!empty($mod->url)) {
			print ' <a href="'.dol_escape_htmltag($mod->url).'" target="_blank" rel="noopener" title="'.dol_escape_htmltag($mod->url).'">'.img_picto($langs->trans('DMMGetAccess'), 'fa-external-link-alt').'</a>';
		}
	} elseif ($upstreamStatus !== null) {
		// Upstream author marked this module with a non-enabled status (soon, beta, etc.)
		// Only shown while developer mode is on — the import step removes these rows when off.
		print '<span class="badge badge-warning" title="'.dol_escape_htmltag($langs->trans('DMMUpstreamStatus').': '.$upstreamStatus).'">'.dol_escape_htmltag($upstreamStatus).'</span>';
	} elseif (!empty($mod->cache_last_error)) {
		print '<span class="badge badge-danger" title="'.dol_escape_htmltag($mod->cache_last_error).'">Error</span>';
	} elseif (!$mod->installed) {
		print '<span class="badge badge-secondary">'.$langs->trans('DMMNotInstalled').'</span>';
	} elseif ($mod->cache_latest_compatible && $mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>')) {
		print '<span class="badge badge-warning">'.$langs->trans('DMMUpdateAvailable').'</span>';
	} elseif ($mod->installed) {
		print '<span class="badge badge-status4">'.$langs->trans('DMMUpToDate').'</span>';
	}
	print '</td>';

	// Actions
	// Action icons. Two of the four are conditional (module setup only when
	// installed, install/update only when there is something to install), so
	// printing them back to back makes each icon land at a different x from row to
	// row. Every slot is always emitted at a fixed width instead — an unavailable
	// action renders as an empty placeholder, which keeps the icons in vertical
	// columns down the table.
	$slot = function ($html) {
		print '<span class="dmm-action-slot">'.$html.'</span>';
	};

	print '<td class="center nowraponall dmm-action-cell">';

	// Unmanaged: no registry row means every id-addressed action is meaningless.
	// The only useful thing here is to give the module a source.
	if (!empty($mod->dmm_unmanaged)) {
		if (dmm_user_can('write')) {
			$slot('<a href="'.$_SERVER['PHP_SELF'].'?action=attachsource&module_id='.urlencode($mod->module_id).'&filter='.$filter.'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('DMMAttachSource')).'">'.img_picto($langs->trans('DMMAttachSource'), 'fa-link').'</a>');
		}
		$slot('<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode($mod->module_id).'" title="'.dol_escape_htmltag($langs->trans('DMMOpenInModuleSetup')).'">'.img_picto($langs->trans('DMMOpenInModuleSetup'), 'fa-puzzle-piece').'</a>');
		print '</td>';
		print '</tr>';
		continue;
	}

	$slot('<a'.dmm_ajax_attrs($langs->trans('DMMCheckNow')).' href="'.$_SERVER['PHP_SELF'].'?action=checkupdate&token='.newToken().'&id='.$mod->id.'&filter='.$filter.'" title="'.$langs->trans('DMMCheckNow').'">'.img_picto($langs->trans('DMMCheckNow'), 'fa-sync').'</a>');

	// Jump to the native module setup page, pre-filtered on this module.
	// search_keyword matches the technical name (= directory name = module_id).
	$slot($mod->installed
		? '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode($mod->module_id).'" title="'.dol_escape_htmltag($langs->trans('DMMOpenInModuleSetup')).'">'.img_picto($langs->trans('DMMOpenInModuleSetup'), 'fa-puzzle-piece').'</a>'
		: '');

	if (dmm_user_can('write')) {
		// Skip the install shortcut for upstream-status-tagged rows — install must go
		// through the detail page's "Install anyway" gate.
		$canAct = ($upstreamStatus === null && $mod->cache_latest_compatible && (!$mod->installed || ($mod->installed_version && version_compare($mod->cache_latest_compatible, $mod->installed_version, '>'))));
		if ($canAct) {
			$actionLabel = $mod->installed ? $langs->trans('DMMUpdate') : $langs->trans('DMMInstall');
			$slot('<a href="'.dol_buildpath('/dolimodulemanager/admin/module.php', 1).'?id='.$mod->id.'&action=confirminstall&token='.newToken().'" title="'.$actionLabel.'">'.img_picto($actionLabel, 'fa-download').'</a>');
		} else {
			$slot('');
		}
		$slot('<a href="'.$_SERVER['PHP_SELF'].'?action=removemodule&token='.newToken().'&id='.$mod->id.'&filter='.$filter.'" title="'.$langs->trans('Delete').'">'.img_picto($langs->trans('Delete'), 'delete').'</a>');
	}
	print '</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Remove confirmation
if ($action == 'removemodule' && $id > 0) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$id.'&filter='.$filter,
		$langs->trans('Delete'),
		$langs->trans('DMMConfirmRemoveModule'),
		'confirm_removemodule',
		'',
		0,
		1
	);
}

// Collapsible help & troubleshooting for first-time users.
print dmm_help_section();

print dol_get_fiche_end();

llxFooter();
$db->close();
