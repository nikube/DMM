<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/DMMModule.class.php
 * \ingroup dolimodulemanager
 * \brief   CRUD class for module registry
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class DMMModule - Module registry and cache management
 */
class DMMModule extends CommonObject
{
	/** @var string */
	public $module = 'dolimodulemanager';

	/** @var string */
	public $element = 'dmmmodule';

	/** @var string */
	public $table_element = 'dmm_module';

	/** @var string */
	public $picto = 'fa-puzzle-piece';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int */
	public $ismultientitymanaged = 0;

	/**
	 * @var array Field definitions
	 */
	public $fields = array(
		'rowid'                   => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 1, 'index' => 1),
		'module_id'               => array('type' => 'varchar(128)', 'label' => 'ModuleId', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 10, 'searchall' => 1),
		'name'                    => array('type' => 'varchar(255)', 'label' => 'Name', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 20, 'searchall' => 1),
		'description'             => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 30),
		'author'                  => array('type' => 'varchar(255)', 'label' => 'Author', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 40),
		'license'                 => array('type' => 'varchar(50)', 'label' => 'License', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 50),
		'url'                     => array('type' => 'varchar(500)', 'label' => 'URL', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 60),
		'github_repo'             => array('type' => 'varchar(255)', 'label' => 'GitHubRepo', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 70),
		'fk_dmm_token'            => array('type' => 'integer', 'label' => 'Token', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 80),
		'installed_version'       => array('type' => 'varchar(20)', 'label' => 'InstalledVersion', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 90),
		'installed'               => array('type' => 'integer', 'label' => 'Installed', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 100, 'default' => 0),
		'cache_latest_version'    => array('type' => 'varchar(20)', 'label' => 'LatestVersion', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 200),
		'cache_latest_compatible' => array('type' => 'varchar(20)', 'label' => 'LatestCompatible', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 201),
		'cache_changelog'         => array('type' => 'text', 'label' => 'Changelog', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 202),
		'cache_manifest_json'     => array('type' => 'text', 'label' => 'ManifestJSON', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 203),
		'cache_etag'              => array('type' => 'varchar(128)', 'label' => 'ETag', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 204),
		'cache_last_check'        => array('type' => 'datetime', 'label' => 'LastCheck', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 205),
		'cache_last_error'        => array('type' => 'varchar(500)', 'label' => 'LastError', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 206),
		'date_creation'           => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 500),
		'tms'                     => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -1, 'notnull' => 0, 'position' => 501),
	);

	/** @var int */
	public $rowid;
	/** @var string */
	public $module_id;
	/** @var string|null */
	public $name;
	/** @var string|null */
	public $description;
	/** @var string|null */
	public $author;
	/** @var string|null */
	public $license;
	/** @var string|null */
	public $url;
	/** @var string */
	public $github_repo;
	/** @var int */
	public $fk_dmm_token;
	/** @var string|null */
	public $installed_version;
	/** @var int */
	public $installed;
	/** @var string|null */
	public $cache_latest_version;
	/** @var string|null */
	public $cache_latest_compatible;
	/** @var string|null */
	public $cache_changelog;
	/** @var string|null */
	public $cache_manifest_json;
	/** @var string|null */
	public $cache_etag;
	/** @var string|null */
	public $cache_last_check;
	/** @var string|null */
	public $cache_last_error;
	/** @var string */
	public $date_creation;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create module entry in database
	 *
	 * @param  User $user      User creating
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function create($user, $notrigger = false)
	{
		$this->date_creation = dol_now('gmt');

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (";
		$sql .= "module_id, name, description, author, license, url, github_repo, fk_dmm_token, installed_version, installed, date_creation";
		$sql .= ") VALUES (";
		$sql .= "'".$this->db->escape($this->module_id)."'";
		$sql .= ", ".($this->name ? "'".$this->db->escape($this->name)."'" : "NULL");
		$sql .= ", ".($this->description ? "'".$this->db->escape($this->description)."'" : "NULL");
		$sql .= ", ".($this->author ? "'".$this->db->escape($this->author)."'" : "NULL");
		$sql .= ", ".($this->license ? "'".$this->db->escape($this->license)."'" : "NULL");
		$sql .= ", ".($this->url ? "'".$this->db->escape($this->url)."'" : "NULL");
		$sql .= ", '".$this->db->escape($this->github_repo)."'";
		$sql .= ", ".((int) $this->fk_dmm_token);
		$sql .= ", ".($this->installed_version ? "'".$this->db->escape($this->installed_version)."'" : "NULL");
		$sql .= ", ".((int) ($this->installed ?? 0));
		$sql .= ", '".$this->db->idate($this->date_creation)."'";
		$sql .= ")";

		$this->db->begin();
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id($this->db->prefix().$this->table_element);
			$this->rowid = $this->id;
			$this->db->commit();
			return $this->id;
		}
		$this->error = $this->db->lasterror();
		$this->db->rollback();
		return -1;
	}

	/**
	 * Load module from database
	 *
	 * @param  int    $id        Row ID
	 * @param  string $module_id Module identifier (alternative to rowid)
	 * @return int               >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id, $module_id = '')
	{
		$sql = "SELECT *";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		if ($id > 0) {
			$sql .= " WHERE rowid = ".((int) $id);
		} elseif (!empty($module_id)) {
			$sql .= " WHERE module_id = '".$this->db->escape($module_id)."'";
		} else {
			return -1;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->id = $obj->rowid;
				$this->rowid = $obj->rowid;
				$this->module_id = $obj->module_id;
				$this->name = $obj->name;
				$this->description = $obj->description;
				$this->author = $obj->author;
				$this->license = $obj->license;
				$this->url = $obj->url;
				$this->github_repo = $obj->github_repo;
				$this->fk_dmm_token = $obj->fk_dmm_token;
				$this->installed_version = $obj->installed_version;
				$this->installed = $obj->installed;
				$this->cache_latest_version = $obj->cache_latest_version;
				$this->cache_latest_compatible = $obj->cache_latest_compatible;
				$this->cache_changelog = $obj->cache_changelog;
				$this->cache_manifest_json = $obj->cache_manifest_json;
				$this->cache_etag = $obj->cache_etag;
				$this->cache_last_check = $this->db->jdate($obj->cache_last_check);
				$this->cache_last_error = $obj->cache_last_error;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Update module in database
	 *
	 * @param  User $user      User modifying
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function update($user, $notrigger = false)
	{
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " module_id = '".$this->db->escape($this->module_id)."'";
		$sql .= ", name = ".($this->name ? "'".$this->db->escape($this->name)."'" : "NULL");
		$sql .= ", description = ".($this->description ? "'".$this->db->escape($this->description)."'" : "NULL");
		$sql .= ", author = ".($this->author ? "'".$this->db->escape($this->author)."'" : "NULL");
		$sql .= ", license = ".($this->license ? "'".$this->db->escape($this->license)."'" : "NULL");
		$sql .= ", url = ".($this->url ? "'".$this->db->escape($this->url)."'" : "NULL");
		$sql .= ", github_repo = '".$this->db->escape($this->github_repo)."'";
		$sql .= ", fk_dmm_token = ".((int) $this->fk_dmm_token);
		$sql .= ", installed_version = ".($this->installed_version ? "'".$this->db->escape($this->installed_version)."'" : "NULL");
		$sql .= ", installed = ".((int) $this->installed);
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		}
		$this->error = $this->db->lasterror();
		$this->db->rollback();
		return -1;
	}

	/**
	 * Delete module from database
	 *
	 * @param  User $user      User deleting
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function delete($user, $notrigger = false)
	{
		// Delete related backups first
		$sql = "DELETE FROM ".$this->db->prefix()."dmm_backup WHERE fk_dmm_module = ".((int) $this->id);
		$this->db->query($sql);

		$sql = "DELETE FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->db->begin();
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		}
		$this->error = $this->db->lasterror();
		$this->db->rollback();
		return -1;
	}

	/**
	 * Invalidate all cache columns
	 *
	 * @return int >0 if OK, <0 if KO
	 */
	public function invalidateCache()
	{
		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " cache_latest_version = NULL";
		$sql .= ", cache_latest_compatible = NULL";
		$sql .= ", cache_changelog = NULL";
		$sql .= ", cache_manifest_json = NULL";
		$sql .= ", cache_etag = NULL";
		$sql .= ", cache_last_check = NULL";
		$sql .= ", cache_last_error = NULL";
		$sql .= " WHERE rowid = ".((int) $this->id);

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->cache_latest_version = null;
			$this->cache_latest_compatible = null;
			$this->cache_changelog = null;
			$this->cache_manifest_json = null;
			$this->cache_etag = null;
			$this->cache_last_check = null;
			$this->cache_last_error = null;
			return 1;
		}
		return -1;
	}

	/**
	 * Update cache columns after a check
	 *
	 * @param  array $data Cache data to update
	 * @return int         >0 if OK, <0 if KO
	 */
	public function updateCache($data)
	{
		$sets = array();
		$sets[] = "cache_last_check = '".$this->db->idate(dol_now('gmt'))."'";

		if (isset($data['latest_version'])) {
			$sets[] = "cache_latest_version = '".$this->db->escape($data['latest_version'])."'";
			$this->cache_latest_version = $data['latest_version'];
		}
		if (isset($data['latest_compatible'])) {
			$sets[] = "cache_latest_compatible = '".$this->db->escape($data['latest_compatible'])."'";
			$this->cache_latest_compatible = $data['latest_compatible'];
		}
		if (isset($data['changelog'])) {
			$changelog = $data['changelog'];
			// Safe truncation: respect UTF-8 boundaries and cut at last newline before limit
			if (mb_strlen($changelog, 'UTF-8') > 2000) {
				$changelog = mb_substr($changelog, 0, 2000, 'UTF-8');
				$lastNewline = strrpos($changelog, "\n");
				if ($lastNewline !== false && $lastNewline > 1500) {
					$changelog = substr($changelog, 0, $lastNewline)."\n…";
				} else {
					$changelog .= '…';
				}
			}
			$sets[] = "cache_changelog = '".$this->db->escape($changelog)."'";
			$this->cache_changelog = $changelog;
		}
		if (isset($data['manifest_json'])) {
			$sets[] = "cache_manifest_json = '".$this->db->escape($data['manifest_json'])."'";
			$this->cache_manifest_json = $data['manifest_json'];
		}
		if (isset($data['etag'])) {
			$sets[] = "cache_etag = '".$this->db->escape($data['etag'])."'";
			$this->cache_etag = $data['etag'];
		}
		if (isset($data['error'])) {
			$sets[] = "cache_last_error = '".$this->db->escape(dol_trunc($data['error'], 500))."'";
			$this->cache_last_error = $data['error'];
		} else {
			$sets[] = "cache_last_error = NULL";
			$this->cache_last_error = null;
		}

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET ".implode(', ', $sets);
		$sql .= " WHERE rowid = ".((int) $this->id);

		$this->cache_last_check = dol_now('gmt');
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Check if cache is stale
	 *
	 * @param  int  $interval Check interval in seconds (default: from settings)
	 * @return bool           True if cache needs refresh
	 */
	public function isCacheStale($interval = 0)
	{
		if (empty($this->cache_last_check)) {
			return true;
		}
		if ($interval <= 0) {
			$interval = (int) dmm_get_setting('check_interval', 86400);
		}
		return (dol_now('gmt') - $this->cache_last_check) > $interval;
	}

	/**
	 * Fetch all modules from database
	 *
	 * @param  string $filter Filter: 'all', 'installed', 'updates', 'notinstalled'
	 * @return array          Array of DMMModule objects
	 */
	public function fetchAll($filter = 'all')
	{
		$modules = array();

		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		switch ($filter) {
			case 'installed':
				$sql .= " WHERE installed = 1";
				break;
			case 'updates':
				// Fetch candidates, then filter with version_compare to avoid false positives on downgrades
				$sql .= " WHERE installed = 1 AND cache_latest_compatible IS NOT NULL AND installed_version IS NOT NULL";
				break;
			case 'notinstalled':
				$sql .= " WHERE installed = 0";
				break;
		}
		$sql .= " ORDER BY module_id ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$mod = new self($this->db);
				$mod->fetch($obj->rowid);
				// For 'updates' filter, only include genuine upgrades (latest > installed)
				if ($filter === 'updates' && version_compare($mod->cache_latest_compatible, $mod->installed_version, '<=')) {
					continue;
				}
				$modules[] = $mod;
			}
		}

		return $modules;
	}
}
