<?php
/* Copyright (C) 2026 DMM Contributors
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
 */

/**
 * \file       core/modules/modDoliModuleManager.class.php
 * \ingroup    dolimodulemanager
 * \brief      Module descriptor for DoliModuleManager
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * DoliModuleManager module descriptor
 */
class modDoliModuleManager extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		$this->numero = 777100;
		$this->rights_class = 'dolimodulemanager';
		$this->family = 'technic';
		$this->module_position = 500;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'DoliModuleManagerDescription';
		$this->descriptionlong = 'DoliModuleManagerDescriptionLong';
		$this->editor_name = 'DMM Contributors';
		$this->editor_url = '';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_DOLIMODULEMANAGER';
		$this->picto = 'fa-cubes';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/dolimodulemanager/temp');
		$this->config_page_url = array('setup.php@dolimodulemanager');
		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('dolimodulemanager@dolimodulemanager');
		$this->phpmin = array(8, 0);
		$this->need_dolibarr_version = array(14, 0, 0);

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		if (!isModEnabled('dolimodulemanager')) {
			$conf->dolimodulemanager = new stdClass();
			$conf->dolimodulemanager->enabled = 0;
		}

		// Permissions
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero . sprintf('%02d', 1);
		$this->rights[$r][1] = 'Read module catalog and check for updates';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero . sprintf('%02d', 2);
		$this->rights[$r][1] = 'Install and update modules, manage tokens';
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero . sprintf('%02d', 3);
		$this->rights[$r][1] = 'Change DMM settings, manage backups';
		$this->rights[$r][4] = 'admin';
		$this->rights[$r][5] = '';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=admintools',
			'type' => 'left',
			'titre' => 'DoliModuleManager',
			'prefix' => img_picto('', 'fa-cubes', 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'home',
			'leftmenu' => 'dolimodulemanager',
			'url' => '/dolimodulemanager/admin/index.php',
			'langs' => 'dolimodulemanager@dolimodulemanager',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("dolimodulemanager")',
			'perms' => '$user->hasRight("dolimodulemanager", "read")',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=dolimodulemanager',
			'type' => 'left',
			'titre' => 'DMMCatalog',
			'mainmenu' => 'home',
			'leftmenu' => 'dolimodulemanager_catalog',
			'url' => '/dolimodulemanager/admin/catalog.php',
			'langs' => 'dolimodulemanager@dolimodulemanager',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("dolimodulemanager")',
			'perms' => '$user->hasRight("dolimodulemanager", "read")',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=dolimodulemanager',
			'type' => 'left',
			'titre' => 'DMMBackups',
			'mainmenu' => 'home',
			'leftmenu' => 'dolimodulemanager_backups',
			'url' => '/dolimodulemanager/admin/backups.php',
			'langs' => 'dolimodulemanager@dolimodulemanager',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("dolimodulemanager")',
			'perms' => '$user->hasRight("dolimodulemanager", "read")',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=dolimodulemanager',
			'type' => 'left',
			'titre' => 'DMMSettings',
			'mainmenu' => 'home',
			'leftmenu' => 'dolimodulemanager_setup',
			'url' => '/dolimodulemanager/admin/setup.php',
			'langs' => 'dolimodulemanager@dolimodulemanager',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("dolimodulemanager")',
			'perms' => '$user->hasRight("dolimodulemanager", "admin")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param  string $options Options when enabling module
	 * @return int             1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/dolimodulemanager/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string $options Options when disabling module
	 * @return int             1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
