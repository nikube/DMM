<?php
/* Copyright (C) 2026 Nicolas Zaou <nz@anatoleconseil.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    scripts/dmm-install.php
 * \brief   Install modules from the command line through DMM — same pipeline as
 *          the "Add public repo" + "Install" buttons (registry row, release
 *          resolution, tarball, backup, deploy), without the browser.
 *
 * Usage:
 *   php dmm-install.php [--activate] [--token=ghp_xxx] [--user=admin] SPEC...
 *
 * SPEC is one of:
 *   multifilter                        name looked up in the registered hubs (falls back to DMMHub)
 *   nikube/objectbanner                GitHub owner/repo
 *   https://github.com/o/r/tree/b/dir  any git URL parsePublicRepoInput() accepts (monorepo subdir)
 *   nikube/DMM@develop                 "@ref" forces a branch (dev channel) or a tag
 *
 * Without @ref the latest compatible release is installed; a repo without
 * releases falls back to its default branch. --activate enables the module
 * afterwards (activateModule(), idempotent). Exit code = number of failures.
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	print "This script is CLI only.\n";
	exit(1);
}

$opts = array('activate' => false, 'token' => null, 'user' => getenv('DOLI_ADMIN_LOGIN') ?: 'admin');
$specs = array();
foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--activate') {
		$opts['activate'] = true;
	} elseif (preg_match('/^--(token|user)=(.+)$/', $arg, $m)) {
		$opts[$m[1]] = $m[2];
	} elseif (strpos($arg, '--') === 0) {
		fwrite(STDERR, "Unknown option $arg\n");
		exit(1);
	} else {
		$specs[] = $arg;
	}
}
if (empty($specs)) {
	print "Usage: php dmm-install.php [--activate] [--token=...] [--user=admin] SPEC...\n";
	exit(0);
}

// custom/dolimodulemanager/scripts → htdocs
$masters = array(__DIR__.'/../../../master.inc.php', __DIR__.'/../../master.inc.php');
foreach ($masters as $master) {
	if (file_exists($master)) {
		require $master;
		break;
	}
}
if (!defined('DOL_DOCUMENT_ROOT')) {
	fwrite(STDERR, "master.inc.php not found\n");
	exit(1);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
dol_include_once('/dolimodulemanager/class/DMMClient.class.php');
dol_include_once('/dolimodulemanager/class/DMMModule.class.php');

// The registry (DMMModule::create) and activateModule() need a real user.
$user = new User($db);
if ($user->fetch(0, $opts['user']) <= 0) {
	fwrite(STDERR, "User '{$opts['user']}' not found\n");
	exit(1);
}
$user->loadRights();

$dmmClient = new DMMClient($db);
$DEFAULT_HUB = 'https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json';

/**
 * Resolve a bare name against the hubs: match on repo basename or entry name,
 * comparing with the same normalisation as module ids (lowercase, [a-z0-9_]).
 *
 * @param  DMMClient $client Client
 * @param  string    $name   Bare name
 * @return string|null       Repo spec, or null when nothing matches
 */
function dmm_cli_hub_lookup($client, $name)
{
	static $entries = null;
	if ($entries === null) {
		$entries = array();
		$urls = array_column(array_filter(dmm_get_hubs(), function ($h) {
			return !empty($h['enabled']);
		}), 'url');
		if (empty($urls)) {
			$urls = array($GLOBALS['DEFAULT_HUB']);
		}
		foreach ($urls as $url) {
			$hub = $client->fetchHub($url);
			foreach ($hub['modules'] ?? array() as $e) {
				if (!empty($e['repo'])) {
					$entries[] = $e;
				}
			}
		}
	}
	$norm = function ($s) {
		return strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $s));
	};
	$want = $norm($name);
	foreach ($entries as $e) {
		if ($norm(basename(rtrim($e['repo'], '/'))) === $want || $norm($e['name'] ?? '') === $want) {
			return $e['repo'];
		}
	}
	return null;
}

$nberr = 0;
print "***** dmm-install.php (Dolibarr ".DOL_VERSION.") *****\n";
foreach ($specs as $spec) {
	$ref = null;
	if (preg_match('/^(.+)@([^@\/]+)$/', $spec, $m) && !preg_match('#^https?://#i', $spec)) {
		$spec = $m[1];
		$ref = $m[2];
	}
	if (strpos($spec, '/') === false) {
		$repoSpec = dmm_cli_hub_lookup($dmmClient, $spec);
		if ($repoSpec === null) {
			print "ERROR $spec: not found in hubs\n";
			$nberr++;
			continue;
		}
		print "$spec → $repoSpec\n";
		$spec = $repoSpec;
	}

	// --- Register (mirror of admin/add.php 'addpublicrepo')
	$parsed = $dmmClient->parsePublicRepoInput($spec);
	if ($parsed === null) {
		print "ERROR $spec: unsupported repo format\n";
		$nberr++;
		continue;
	}
	$manifest = array();
	if ($parsed['host'] === 'github') {
		$manifest = $dmmClient->fetchManifest($parsed['owner'], $parsed['repo'], $opts['token']);
		if (!is_array($manifest)) {
			$manifest = array();
		}
	}
	$module_id = dmm_module_id_from_parsed($parsed, $manifest['module_id'] ?? null);

	$mod = new DMMModule($db);
	if ($mod->fetch(0, $module_id) <= 0) {
		$mod = new DMMModule($db);
		$mod->module_id = $module_id;
		$mod->github_repo = $parsed['project'];
		$mod->git_host = $parsed['host'];
		$mod->git_base_url = $parsed['base_url'];
		$mod->subdir = $parsed['subdir'];
		dmm_apply_parsed_branch($mod, $parsed);
		$mod->fk_dmm_token = null;
		$mod->name = $manifest['name'] ?? null;
		$mod->description = $manifest['description'] ?? null;
		$mod->author = $manifest['author'] ?? null;
		$mod->license = $manifest['license'] ?? null;
		$mod->url = $manifest['url'] ?? null;
		$installedVersion = $dmmClient->getInstalledVersion($module_id);
		if ($installedVersion !== null || is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$module_id)) {
			$mod->installed = 1;
			$mod->installed_version = $installedVersion;
		}
		if ($mod->create($user) <= 0) {
			print "ERROR $module_id: cannot register (".$mod->error.")\n";
			$nberr++;
			continue;
		}
		$mod->fetch(0, $module_id);
	}

	// --- Pick the ref (mirror of admin/module.php 'confirm_install')
	$channel = 'stable';
	if ($ref !== null) {
		$tag = $ref;
		$channel = preg_match('/^v?\d/', $ref) ? 'stable' : 'dev';
		if ($channel === 'dev' && ($mod->channel !== 'dev' || $mod->branch_dev !== $ref)) {
			// Same as admin/module.php 'setchannel': the row tracks the branch from now on.
			$mod->channel = 'dev';
			$mod->branch_dev = $ref;
			$mod->invalidateCache();
			$mod->update($user);
		}
	} elseif (dmm_module_tracks_branch($mod)) {
		$tag = $mod->branch_dev;
		$channel = 'dev';
	} else {
		$check = $dmmClient->checkUpdate($module_id, $opts['token'], $mod->github_repo);
		$tag = $check['download_tag'] ?? null;
		if (empty($tag)) {
			$why = !empty($check['latest_version'])
				? "latest ".$check['latest_version']." is not compatible with Dolibarr ".DOL_VERSION." (dmm.json compatibility) — force with @v".$check['latest_version']
				: "no release found".($dmmClient->error ? " (".$dmmClient->error.")" : '')." — give an explicit @branch";
			print "ERROR $module_id: $why\n";
			$nberr++;
			continue;
		}
		if (strpos((string) ($check['latest_compatible_version'] ?? ''), 'dev:') === 0) {
			$channel = 'dev';
		}
	}

	// --- Install
	$result = $dmmClient->installOrUpdate($module_id, $tag, $opts['token'], $mod->github_repo, $channel);
	if (empty($result['success'])) {
		print "ERROR $module_id: ".($result['message'] ?? 'install failed')."\n";
		$nberr++;
		continue;
	}
	print "Installed $module_id @ $tag\n";

	if ($opts['activate']) {
		$class = $dmmClient->getDescriptorClass($module_id);
		$res = $class ? activateModule($class, 1) : array('errors' => array('no descriptor found'));
		if (!empty($res['errors'])) {
			print "ERROR $module_id: activation failed: ".implode(', ', $res['errors'])."\n";
			$nberr++;
		} else {
			print "Activated $class\n";
		}
		$conf->setValues($db);
	}
}
exit($nberr);
