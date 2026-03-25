<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * Standalone rescue script for DoliModuleManager.
 * Use this if DMM breaks after a self-update.
 * Access directly: /custom/dolimodulemanager/dmm_rescue.php
 *
 * This script has ZERO dependencies on DMM classes.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// Admin only
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'confirm_restore') {
	$backup_path = GETPOST('backup_path', 'alphanohtml');

	if (!empty($backup_path) && is_dir($backup_path)) {
		$targetDir = DOL_DOCUMENT_ROOT.'/custom/dolimodulemanager';

		// Remove current (broken) module directory
		if (is_dir($targetDir)) {
			dol_delete_dir_recursive($targetDir);
		}

		if (is_dir($targetDir)) {
			$error = 'Failed to remove current module directory. Files may be locked. Try restarting your web server first.';
		} else {
			$result = dolCopyDir($backup_path, $targetDir, '0', 1);
			if ($result >= 0) {
				// Update backup status in DB
				$sql = "UPDATE ".$db->prefix()."dmm_backup SET status = 'restored' WHERE backup_path = '".$db->escape($backup_path)."'";
				$db->query($sql);

				$success = 'DoliModuleManager restored successfully. Please re-activate the module from Dolibarr admin (Home > Setup > Modules).';
			} else {
				$error = 'Failed to copy backup files. Check file permissions.';
			}
		}
	} else {
		$error = 'Backup directory not found: '.$backup_path;
	}
}

/*
 * View
 */

llxHeader('', 'DMM Rescue', '', '', 0, 0, '', '', '', 'mod-dolimodulemanager page-rescue');

print '<h1>'.img_picto('', 'fa-life-ring', 'class="pictofixedwidth"').' DoliModuleManager — Rescue</h1>';
print '<p>This standalone script can restore DMM from a backup if a self-update went wrong.</p>';

if (!empty($error)) {
	print '<div class="error">'.$error.'</div><br>';
}
if (!empty($success)) {
	print '<div class="ok">'.$success.'</div><br>';
	print '<p><a class="butAction" href="'.DOL_URL_ROOT.'/admin/modules.php">Go to Modules admin</a></p>';
	llxFooter();
	$db->close();
	exit;
}

// Find available backups for dolimodulemanager
$sql = "SELECT rowid, version_from, version_to, backup_path, backup_size, status, date_creation";
$sql .= " FROM ".$db->prefix()."dmm_backup";
$sql .= " WHERE module_id = 'dolimodulemanager'";
$sql .= " ORDER BY date_creation DESC";
$sql .= " LIMIT 10";

$resql = $db->query($sql);
if ($resql && $db->num_rows($resql) > 0) {
	print '<h3>Available backups</h3>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>From version</td>';
	print '<td>To version</td>';
	print '<td>Date</td>';
	print '<td>Size</td>';
	print '<td>Status</td>';
	print '<td>Path exists</td>';
	print '<td class="center">Action</td>';
	print '</tr>';

	while ($obj = $db->fetch_object($resql)) {
		$pathExists = is_dir($obj->backup_path);
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($obj->version_from).'</td>';
		print '<td>'.dol_escape_htmltag($obj->version_to).'</td>';
		print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhour').'</td>';
		print '<td>'.($obj->backup_size ? dol_print_size($obj->backup_size, 0) : '-').'</td>';
		print '<td>'.dol_escape_htmltag($obj->status).'</td>';
		print '<td>'.($pathExists ? '<span class="badge badge-status4">Yes</span>' : '<span class="badge badge-danger">No</span>').'</td>';
		print '<td class="center">';
		if ($pathExists && $obj->status === 'ok') {
			print '<a class="butAction butActionSmall" href="'.$_SERVER['PHP_SELF'].'?action=restore&backup_path='.urlencode($obj->backup_path).'&token='.newToken().'">Restore this version</a>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';
} else {
	print '<div class="warning">No backups found for DoliModuleManager in the database.<br>';
	print 'Check manually in: <code>'.DOL_DATA_ROOT.'/dolimodulemanager/backups/</code></div>';
}

// Restore confirmation
if ($action == 'restore') {
	$backup_path = GETPOST('backup_path', 'alphanohtml');
	$form = new Form($db);
	print $form->formconfirm(
		$_SERVER['PHP_SELF'].'?backup_path='.urlencode($backup_path),
		'Restore DMM',
		'Are you sure you want to restore DoliModuleManager from this backup? The current (broken) version will be replaced.<br><br><code>'.dol_escape_htmltag($backup_path).'</code>',
		'confirm_restore',
		'',
		0,
		1
	);
}

llxFooter();
$db->close();
