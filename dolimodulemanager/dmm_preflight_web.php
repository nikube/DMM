<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * Web-based preflight check for DoliModuleManager.
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
dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');

llxHeader('', 'DMM Preflight', '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-preflight');

print load_fiche_titre(img_picto('', 'fa-stethoscope', 'class="pictofixedwidth"').' Preflight Check', '<a href="'.dol_buildpath('/dolimodulemanager/admin/index.php', 1).'">'.$langs->trans('Back').'</a>', '');

$phpUser = dmm_get_php_user();
$customDir = DOL_DOCUMENT_ROOT.'/custom';
$permProblems = array();

// Single table for all checks
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';

// -- PHP --
print '<tr class="liste_titre"><td colspan="3">PHP</td></tr>';
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
pc('PHP version', $phpOk, PHP_VERSION);
foreach (array('curl', 'json', 'phar', 'openssl', 'zlib', 'mbstring') as $ext) {
	$loaded = extension_loaded($ext);
	pc($ext, $loaded, $loaded ? 'loaded' : 'missing');
}
pc('Process user', true, $phpUser);

// -- Dolibarr --
print '<tr class="liste_titre"><td colspan="3">Dolibarr</td></tr>';
pc('Version', version_compare(DOL_VERSION, '14.0.0', '>='), DOL_VERSION);
$encOk = function_exists('dolEncrypt') && function_exists('dolDecrypt');
pc('dolEncrypt', $encOk, $encOk ? 'ok' : 'missing');

// -- Filesystem --
print '<tr class="liste_titre"><td colspan="3">Filesystem</td></tr>';
pc('/custom/', is_dir($customDir) && is_writable($customDir), $customDir);
$freeSpace = @disk_free_space($customDir);
if ($freeSpace !== false) {
	$freeMB = round($freeSpace / 1024 / 1024);
	pc('Disk space', $freeMB >= 50, $freeMB.' MB');
}

// -- GitHub --
print '<tr class="liste_titre"><td colspan="3">GitHub</td></tr>';
$ch = curl_init('https://api.github.com/rate_limit');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('User-Agent: DMM/1.0'), CURLOPT_TIMEOUT => 10));
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode === 200) {
	$data = json_decode($resp, true);
	pc('API', true, 'rate: '.($data['rate']['remaining'] ?? '?').'/'.($data['rate']['limit'] ?? '?'));
} else {
	pc('API', false, 'HTTP '.$httpCode);
}
pc('PharData', class_exists('PharData'), class_exists('PharData') ? 'ok' : 'missing');

// -- Module directories --
print '<tr class="liste_titre"><td colspan="3">Module permissions</td></tr>';
if (is_dir($customDir)) {
	$dirs = array_filter(scandir($customDir), function ($d) use ($customDir) {
		return $d[0] !== '.' && is_dir($customDir.'/'.$d);
	});
	foreach ($dirs as $d) {
		$path = $customDir.'/'.$d;
		$owner = dmm_get_file_owner($path);
		$mode = substr(sprintf('%o', @fileperms($path)), -4);
		$ok = is_writable($path);
		$badFile = '';
		if ($ok) {
			foreach (array_slice(@glob($path.'/*') ?: array(), 0, 5) as $f) {
				if (!is_writable($f)) {
					$ok = false;
					$badFile = basename($f).' ('.substr(sprintf('%o', @fileperms($f)), -4).')';
					break;
				}
			}
		}
		if (!$ok) {
			$permProblems[] = $d;
		}
		$detail = $owner.':'.$mode;
		if ($badFile) {
			$detail .= ' — '.$badFile;
		}
		pc($d, $ok, $detail);
	}
}

print '</table>';
print '</div>';

// Fix commands
if (!empty($permProblems)) {
	print '<div class="warning" style="margin-top:10px;">';
	print '<code>chown -R '.$phpUser.':'.$phpUser.' '.$customDir.'/ && chmod -R u+w '.$customDir.'/</code>';
	print '</div>';
}

// Navigation
print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/index.php', 1).'">'.$langs->trans('DMMDashboard').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/dolimodulemanager/admin/setup.php', 1).'">'.$langs->trans('DMMSettingsTab').'</a>';
print '</div>';

llxFooter();
$db->close();

function pc($label, $ok, $detail = '')
{
	$badge = $ok ? '<span class="badge badge-status4">OK</span>' : '<span class="badge badge-danger">FAIL</span>';
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td><td class="center">'.$badge.'</td><td class="small">'.dol_escape_htmltag($detail).'</td></tr>';
}
