<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/dolimodulemanager.lib.php
 * \ingroup dolimodulemanager
 * \brief   Library functions for DoliModuleManager
 */

/**
 * Prepare admin pages header tabs
 *
 * @param  string $active Active tab identifier
 * @return array<array{string,string,string}>
 */
function dolimodulemanagerAdminPrepareHead($active = 'dashboard')
{
	global $langs, $conf, $db;

	$langs->load('dolimodulemanager@dolimodulemanager');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/index.php', 1);
	$head[$h][1] = $langs->trans('DMMDashboard');
	$head[$h][2] = 'dashboard';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/backups.php', 1);
	$head[$h][1] = $langs->trans('DMMBackupsTab');
	$head[$h][2] = 'backups';
	$h++;

	$head[$h][0] = dol_buildpath('/dolimodulemanager/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('DMMSettingsTab');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolimodulemanager@dolimodulemanager', 'remove');

	return $head;
}

/**
 * Sanitize a module ID to prevent path traversal.
 * Only allows lowercase letters, numbers and underscores.
 *
 * @param  string      $id Raw module ID
 * @return string|false    Sanitized ID or false if invalid
 */
function dmm_sanitize_module_id($id)
{
	$id = trim(strtolower($id));
	if (!preg_match('/^[a-z0-9_]+$/', $id)) {
		return false;
	}
	return $id;
}

/**
 * Check if a module ID is a core Dolibarr module that must not be overwritten.
 *
 * @param  string $id Module ID
 * @return bool       True if core module
 */
function dmm_is_core_module($id)
{
	static $coreModules = null;

	if ($coreModules === null) {
		$coreModules = array();
		$coreDir = DOL_DOCUMENT_ROOT.'/core/modules/';
		if (is_dir($coreDir)) {
			$files = glob($coreDir.'mod*.class.php');
			foreach ($files as $f) {
				$className = basename($f, '.class.php');
				$modName = strtolower(preg_replace('/^mod/i', '', $className));
				if ($modName !== '') {
					$coreModules[$modName] = true;
				}
			}
		}
	}

	return isset($coreModules[strtolower($id)]);
}

/**
 * Get a DMM setting value from llx_dmm_setting.
 *
 * @param  string      $name    Setting key
 * @param  string|null $default Default value if not found
 * @return string|null
 */
function dmm_get_setting($name, $default = null)
{
	global $db;

	$sql = "SELECT value FROM ".$db->prefix()."dmm_setting WHERE name = '".$db->escape($name)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		return $obj->value;
	}
	return $default;
}

/**
 * Set a DMM setting value in llx_dmm_setting.
 *
 * @param  string $name  Setting key
 * @param  string $value Setting value
 * @return int           1 on success, -1 on error
 */
function dmm_set_setting($name, $value)
{
	global $db;

	$sql = "SELECT rowid FROM ".$db->prefix()."dmm_setting WHERE name = '".$db->escape($name)."'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$sql = "UPDATE ".$db->prefix()."dmm_setting SET value = '".$db->escape($value)."' WHERE name = '".$db->escape($name)."'";
	} else {
		$sql = "INSERT INTO ".$db->prefix()."dmm_setting (name, value) VALUES ('".$db->escape($name)."', '".$db->escape($value)."')";
	}

	$resql = $db->query($sql);
	return $resql ? 1 : -1;
}

/**
 * Auto-check all modules for updates if cache is stale.
 * Called on page load when auto_check setting is enabled.
 * Only checks modules whose cache has expired.
 *
 * @return int Number of modules checked (0 if nothing to do)
 */
function dmm_auto_check_updates()
{
	global $db;

	if (dmm_get_setting('auto_check', '1') !== '1') {
		return 0;
	}

	dol_include_once('/dolimodulemanager/class/DMMModule.class.php');
	dol_include_once('/dolimodulemanager/class/DMMToken.class.php');
	dol_include_once('/dolimodulemanager/class/DMMClient.class.php');

	$dmmModule = new DMMModule($db);
	$dmmClient = new DMMClient($db);
	$allMods = $dmmModule->fetchAll();
	$checked = 0;

	foreach ($allMods as $mod) {
		if (!$mod->isCacheStale()) {
			continue;
		}

		$tokenObj = new DMMToken($db);
		$tokenObj->fetch($mod->fk_dmm_token);
		$plainToken = $tokenObj->getDecryptedToken();
		if (empty($plainToken)) {
			continue;
		}

		$dmmClient->checkUpdate($mod->module_id, $plainToken, $mod->github_repo);
		$checked++;
	}

	return $checked;
}
