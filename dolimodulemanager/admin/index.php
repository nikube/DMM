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
// Two views over what is installed on this Dolibarr: the modules DMM can manage
// (it knows their source) and the ones it cannot yet.
$filter = GETPOST('filter', 'alpha');
if (!in_array($filter, array('managed', 'unmanaged'), true)) {
	$filter = 'managed';
}
$isAjax = dmm_is_ajax_request();

$dmmModule = new DMMModule($db);
$dmmToken = new DMMToken($db);
$dmmClient = new DMMClient($db);
$form = new Form($db);

/*
 * Actions
 */

// Attach a source to a module that is on disk but unknown to DMM. Registering it
// is what turns "present" into "managed": update checks and installs both need to
// know where the module comes from.
if ($action == 'confirm_attachsource' && dmm_user_can('write')) {
	$attachId = GETPOST('module_id', 'alphanohtml');
	// The id lands in a filesystem path and in SQL — only accept a plain directory
	// name that really exists under custom/.
	if (!preg_match('/^[a-zA-Z0-9_-]+$/', $attachId) || !is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$attachId)) {
		setEventMessages($langs->trans('DMMScanBadSource'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
		exit;
	}

	$repoSpec = trim((string) GETPOST('attach_repo', 'alphanohtml'));
	$dsRaw = trim((string) GETPOST('attach_dsid', 'alphanohtml'));
	$source = null;
	$err = null;

	if ($repoSpec !== '') {
		$git = $dmmClient->parseRepoSpec($repoSpec);
		if (strpos($git['repo'], '/') === false) {
			$err = $langs->trans('DMMScanBadRepo');
		} else {
			$source = array(
				'github_repo' => $git['repo'],
				'git_host' => $git['git_host'],
				'git_base_url' => $git['git_base_url'],
			);
		}
	} elseif ($dsRaw !== '') {
		$dsId = dmm_parse_dolistore_id($dsRaw);
		if ($dsId <= 0) {
			$err = $langs->trans('DMMScanBadDsId');
		} else {
			$source = array(
				'source' => 'dolistore',
				'dolistore_id' => $dsId,
				'github_repo' => 'dolistore:'.$dsId,
			);
		}
	} else {
		$err = $langs->trans('DMMAttachNoSourceGiven');
	}

	if ($err !== null) {
		setEventMessages($err, null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
		exit;
	}

	// registerScannedModule() already sets installed=1 and reads the version off
	// disk, which is exactly right for a module that is physically present.
	$res = $dmmClient->registerScannedModule($attachId, $source);
	if (!empty($res['ok'])) {
		setEventMessages($langs->trans('DMMAttachSourceDone', $attachId), null, 'mesgs');
	} else {
		setEventMessages($res['error'] ?: 'attach failed', null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.$filter);
	exit;
}

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
	vertical-align: middle;
}
/* The on/off switch is a wider image than the action pictos. */
.dmm-action-slot:first-child { width: 40px; }
.dmm-action-slot + .dmm-action-slot { margin-left: 4px; }
.dmm-action-slot .pictofixedwidth { padding-right: 0; }
/* Dolibarr gives .tabsAction a large bottom margin meant for a full action bar;
   here it just leaves a gap between the buttons and the filter tabs. */
.page-admin-index .tabsAction { margin-bottom: 6px; }
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

// ---- Action buttons ----
print '<div class="tabsAction">';
print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMRefreshSources')).' href="'.$_SERVER['PHP_SELF'].'?action=refreshsources&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMRefreshSources').'</a>';
// Only the installed scope is offered here: "check everything" would also walk
// registry rows this screen no longer shows (modules known but not installed —
// they live on the "Add a module" tab now).
print '<a class="butAction"'.dmm_ajax_attrs($langs->trans('DMMCheckInstalledNow')).' data-dmm-batch="module-checks" data-dmm-scope="installed" href="'.$_SERVER['PHP_SELF'].'?action=checkinstalled&token='.newToken().'&filter='.$filter.'">'.$langs->trans('DMMCheckInstalledNow').'</a>';
print '</div>';

// ---- Module list ----
// This screen is about what is installed on this Dolibarr, so the disk decides
// what appears: a module is here because its files are here. The only question
// left is whether DMM knows where it came from — managed or not. Registry rows
// with no files are modules the user could install, not modules they have; they
// belong on the "Add a module" tab, and keeping them here would drown the list
// as soon as a hub of any size is imported.
$onDisk = $dmmClient->listInstalledOnDisk();

$byId = array();
foreach ($dmmModule->fetchAll('all') as $r) {
	$byId[$r->module_id] = $r;
}

$managed = array();
$unmanaged = array();
foreach ($onDisk as $mid => $info) {
	if (isset($byId[$mid])) {
		$row = $byId[$mid];
		// The registry may still carry installed=0 from an import; the files say
		// otherwise, and the files win.
		$row->installed = 1;
		if (empty($row->installed_version) && $info['version'] !== null) {
			$row->installed_version = $info['version'];
		}
		$managed[] = $row;
	} else {
		// Synthesised as a DMMModule-shaped row so the rendering loop below stays
		// a single code path.
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
// DMM itself is filtered out of the disk listing (it does not manage itself), but
// it is a managed module by any reasonable reading, so add its row back.
if (isset($byId['dolimodulemanager'])) {
	array_unshift($managed, $byId['dolimodulemanager']);
}

// ---- Filter tabs ----
$counts = array('managed' => count($managed), 'unmanaged' => count($unmanaged));
print '<div class="tabs" data-role="controlgroup" data-type="horizontal">';
foreach (array('managed' => 'DMMFilterManaged', 'unmanaged' => 'DMMFilterUnmanaged') as $fkey => $flabel) {
	$active = ($filter === $fkey) ? ' inline-block tabactive' : ' inline-block';
	print '<div class="'.$active.'"><a class="tab" href="'.$_SERVER['PHP_SELF'].'?filter='.$fkey.'">'.$langs->trans($flabel).' <span class="badge badge-secondary">'.$counts[$fkey].'</span></a></div>';
}
print '</div><div class="clearboth"></div>';

$modules = ($filter === 'unmanaged') ? $unmanaged : $managed;

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

	// Enable/disable, same switch Dolibarr's own module list uses. Installing a
	// module and turning it on are two different things, and the dashboard was
	// silent about the second — a module could sit here "up to date" while being
	// switched off in Dolibarr.
	$descClass = $dmmClient->getDescriptorClass($mod->module_id);
	$isEnabled = (getDolGlobalString('MAIN_MODULE_'.strtoupper($mod->module_id)) !== '');
	$toggleLabel = $langs->trans($isEnabled ? 'DMMDisableModule' : 'DMMEnableModule');
	if ($descClass !== null && dmm_user_can('write')) {
		$toggleAction = $isEnabled ? 'reset' : 'set';
		$toggleUrl = DOL_URL_ROOT.'/admin/modules.php?action='.$toggleAction.'&token='.newToken().'&value='.urlencode($descClass).'&mode=common&search_keyword='.urlencode($mod->module_id);
		$slot('<a href="'.$toggleUrl.'" title="'.dol_escape_htmltag($toggleLabel).'">'.img_picto($toggleLabel, $isEnabled ? 'switch_on' : 'switch_off').'</a>');
	} else {
		$slot($isEnabled ? img_picto($toggleLabel, 'switch_on') : '');
	}

	// Unmanaged: no registry row means every id-addressed action is meaningless.
	// The only useful thing here is to give the module a source.
	if (!empty($mod->dmm_unmanaged)) {
		if (dmm_user_can('write')) {
			$slot('<a href="'.$_SERVER['PHP_SELF'].'?action=attachsource&module_id='.urlencode($mod->module_id).'&filter='.$filter.'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans('DMMAttachSource')).'">'.img_picto($langs->trans('DMMAttachSource'), 'fa-link').'</a>');
		} else {
			$slot('');
		}
		$slot('<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode($mod->module_id).'" title="'.dol_escape_htmltag($langs->trans('DMMOpenInModuleSetup')).'">'.img_picto($langs->trans('DMMOpenInModuleSetup'), 'fa-cog').'</a>');
		print '</td>';
		print '</tr>';
		continue;
	}

	$slot('<a'.dmm_ajax_attrs($langs->trans('DMMCheckNow')).' href="'.$_SERVER['PHP_SELF'].'?action=checkupdate&token='.newToken().'&id='.$mod->id.'&filter='.$filter.'" title="'.$langs->trans('DMMCheckNow').'">'.img_picto($langs->trans('DMMCheckNow'), 'fa-sync').'</a>');

	// Jump to the native module setup page, pre-filtered on this module.
	// search_keyword matches the technical name (= directory name = module_id).
	$slot($mod->installed
		? '<a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword='.urlencode($mod->module_id).'" title="'.dol_escape_htmltag($langs->trans('DMMOpenInModuleSetup')).'">'.img_picto($langs->trans('DMMOpenInModuleSetup'), 'fa-cog').'</a>'
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

// Attach-a-source dialog for an unmanaged module. An unmanaged module has no
// registry row, so there is no module card to send the user to — the form comes
// to the row instead. Fill either field: a repo, or a DoliStore product.
if ($action == 'attachsource' && dmm_user_can('write')) {
	$attachId = GETPOST('module_id', 'alphanohtml');
	if (preg_match('/^[a-zA-Z0-9_-]+$/', $attachId) && is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$attachId)) {
		$searchUrl = dmm_dolistore_search_url($attachId);
		$formquestion = array(
			array('type' => 'hidden', 'name' => 'module_id', 'value' => $attachId),
			array(
				'type' => 'text',
				'name' => 'attach_repo',
				'label' => $langs->trans('DMMScanSourceGithub'),
				'value' => '',
				'size' => 40,
				'moreattr' => 'placeholder="owner/repo"',
			),
			array(
				'type' => 'text',
				'name' => 'attach_dsid',
				'label' => $langs->trans('DMMScanSourceDolistore').' <a href="'.$searchUrl.'" target="_blank" rel="noopener noreferrer" title="'.dol_escape_htmltag($langs->trans('DMMScanSearchDolistore')).'">'.img_picto('', 'search').'</a>',
				'value' => '',
				'size' => 40,
				'moreattr' => 'placeholder="'.dol_escape_htmltag($langs->trans('DMMScanDsIdPlaceholder')).'"',
			),
		);
		print $form->formconfirm(
			$_SERVER['PHP_SELF'].'?filter='.$filter,
			$langs->trans('DMMAttachSource').' — '.$attachId,
			$langs->trans('DMMAttachSourceHelp'),
			'confirm_attachsource',
			$formquestion,
			0,
			1,
			300
		);
	}
}

// Collapsible help & troubleshooting for first-time users.
print dmm_help_section();

print dol_get_fiche_end();

llxFooter();
$db->close();
