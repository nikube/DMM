<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * Web-based preflight check for DoliModuleManager.
 * Shows system diagnostics in the Dolibarr admin UI.
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

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'dolimodulemanager@dolimodulemanager'));

llxHeader('', 'DMM Preflight', '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-preflight');

print '<h1>'.img_picto('', 'fa-stethoscope', 'class="pictofixedwidth"').' DoliModuleManager — Preflight Check</h1>';

dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
$phpUser = dmm_get_php_user();
$checks = array();

// ---- PHP ----
print '<h3>PHP Environment</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Check</td><td>Status</td><td>Detail</td></tr>';

// PHP version
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
printCheck('PHP version', $phpOk, PHP_VERSION.($phpOk ? '' : ' — requires 8.0+'));

// Extensions
$exts = array('curl', 'json', 'phar', 'openssl', 'zlib', 'mbstring');
foreach ($exts as $ext) {
	$loaded = extension_loaded($ext);
	printCheck('ext-'.$ext, $loaded, $loaded ? 'loaded' : 'NOT loaded');
}

printCheck('PHP user', true, $phpUser);

print '</table>';

// ---- Dolibarr ----
print '<h3>Dolibarr</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Check</td><td>Status</td><td>Detail</td></tr>';

printCheck('Dolibarr version', version_compare(DOL_VERSION, '14.0.0', '>='), DOL_VERSION);

$encryptOk = function_exists('dolEncrypt') && function_exists('dolDecrypt');
printCheck('dolEncrypt/dolDecrypt', $encryptOk, $encryptOk ? 'available' : 'not available');

print '</table>';

// ---- Filesystem ----
print '<h3>Filesystem</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Check</td><td>Status</td><td>Detail</td></tr>';

$customDir = DOL_DOCUMENT_ROOT.'/custom';
printCheck('/custom/ exists', is_dir($customDir), $customDir);
printCheck('/custom/ writable', is_writable($customDir), is_writable($customDir) ? 'yes' : 'no — run: chown -R '.$phpUser.':'.$phpUser.' '.$customDir.'/');

$freeSpace = @disk_free_space($customDir);
if ($freeSpace !== false) {
	$freeMB = round($freeSpace / 1024 / 1024);
	printCheck('Disk space', $freeMB >= 50, $freeMB.' MB free');
}

print '</table>';

// ---- Module permissions ----
print '<h3>Module Directory Permissions</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Module</td><td>Status</td><td>Owner</td></tr>';

$permProblems = array();
if (is_dir($customDir)) {
	$dirs = array_filter(scandir($customDir), function ($d) use ($customDir) {
		return $d[0] !== '.' && is_dir($customDir.'/'.$d);
	});
	foreach ($dirs as $d) {
		$path = $customDir.'/'.$d;
		$owner = dmm_get_file_owner($path);
		$mode = substr(sprintf('%o', @fileperms($path)), -4);
		$dirWritable = is_writable($path);
		// Also check files inside
		$filesOk = true;
		$badFile = '';
		$files = @glob($path.'/*');
		foreach (array_slice($files ?: array(), 0, 10) as $f) {
			if (!is_writable($f)) {
				$filesOk = false;
				$badFile = basename($f).' ('.substr(sprintf('%o', @fileperms($f)), -4).')';
				break;
			}
		}
		$ok = $dirWritable && $filesOk;
		if (!$ok) {
			$permProblems[] = $d;
		}
		$detail = 'owner:'.$owner.' mode:'.$mode;
		if (!$filesOk) {
			$detail .= ' — file not writable: '.$badFile;
		}
		printCheck($d, $ok, $detail);
	}
}

print '</table>';

if (!empty($permProblems)) {
	print '<div class="warning" style="margin-top:10px;">';
	print '<strong>Fix commands:</strong><br>';
	print '<code style="font-size:14px;">chown -R '.$phpUser.':'.$phpUser.' '.$customDir.'/</code><br>';
	print '<code style="font-size:14px;">chmod -R u+w '.$customDir.'/</code>';
	print '</div>';
}

// ---- GitHub ----
print '<h3>GitHub API</h3>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Check</td><td>Status</td><td>Detail</td></tr>';

$ch = curl_init('https://api.github.com/rate_limit');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('User-Agent: DMM/1.0'), CURLOPT_TIMEOUT => 10));
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
	$data = json_decode($resp, true);
	$remaining = $data['rate']['remaining'] ?? '?';
	$limit = $data['rate']['limit'] ?? '?';
	printCheck('GitHub API reachable', true, 'Rate limit: '.$remaining.'/'.$limit);
} else {
	printCheck('GitHub API reachable', false, 'HTTP '.$httpCode);
}

// PharData
$pharOk = class_exists('PharData');
printCheck('PharData available', $pharOk, $pharOk ? 'yes' : 'Phar extension missing');

print '</table>';

// ---- Summary ----
print '<br>';
print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/index.php', 1).'">'.$langs->trans('DMMDashboard').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'">'.$langs->trans('DMMSettingsTab').'</a>';
print '</div>';

llxFooter();
$db->close();

/**
 * Print a check row in the table
 */
function printCheck($label, $ok, $detail = '')
{
	$status = $ok ? '<span class="badge badge-status4">OK</span>' : '<span class="badge badge-danger">FAIL</span>';
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($label).'</td>';
	print '<td class="center">'.$status.'</td>';
	print '<td>'.dol_escape_htmltag($detail).'</td>';
	print '</tr>';
}
