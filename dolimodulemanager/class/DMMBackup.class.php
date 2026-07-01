<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/DMMBackup.class.php
 * \ingroup dolimodulemanager
 * \brief   CRUD class for module backups
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Class DMMBackup - Module backup management
 */
class DMMBackup extends CommonObject
{
	/** @var string */
	public $module = 'dolimodulemanager';

	/** @var string */
	public $element = 'dmmbackup';

	/** @var string */
	public $table_element = 'dmm_backup';

	/** @var string */
	public $picto = 'fa-archive';

	/** @var int */
	public $isextrafieldmanaged = 0;

	/** @var int */
	public $ismultientitymanaged = 0;

	/**
	 * @var array Field definitions
	 */
	public $fields = array(
		'rowid'         => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 1, 'index' => 1),
		'fk_dmm_module' => array('type' => 'integer', 'label' => 'Module', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 10),
		'module_id'     => array('type' => 'varchar(128)', 'label' => 'ModuleId', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 20),
		'version_from'  => array('type' => 'varchar(20)', 'label' => 'VersionFrom', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 30),
		'version_to'    => array('type' => 'varchar(20)', 'label' => 'VersionTo', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 40),
		'backup_path'   => array('type' => 'varchar(500)', 'label' => 'BackupPath', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 50),
		'backup_size'   => array('type' => 'integer', 'label' => 'BackupSize', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 60),
		'status'        => array('type' => 'varchar(20)', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 70, 'default' => 'ok'),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 500),
	);

	/** @var int */
	public $id;
	/** @var int */
	public $rowid;
	/** @var int */
	public $fk_dmm_module;
	/** @var string */
	public $module_id;
	/** @var string */
	public $version_from;
	/** @var string */
	public $version_to;
	/** @var string */
	public $backup_path;
	/** @var int|null */
	public $backup_size;
	/** @var string */
	public $status;
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
	 * Create backup record in database
	 *
	 * @param  User $user      User creating
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function create($user, $notrigger = false)
	{
		$this->date_creation = dol_now('gmt');

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (";
		$sql .= "fk_dmm_module, module_id, version_from, version_to, backup_path, backup_size, status, date_creation";
		$sql .= ") VALUES (";
		$sql .= ($this->fk_dmm_module ? ((int) $this->fk_dmm_module) : "NULL");
		$sql .= ", '".$this->db->escape($this->module_id)."'";
		$sql .= ", '".$this->db->escape($this->version_from)."'";
		$sql .= ", '".$this->db->escape($this->version_to)."'";
		$sql .= ", '".$this->db->escape($this->backup_path)."'";
		$sql .= ", ".($this->backup_size !== null ? ((int) $this->backup_size) : "NULL");
		$sql .= ", '".$this->db->escape($this->status ?: 'ok')."'";
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
	 * Load backup from database
	 *
	 * @param  int $id Row ID
	 * @return int     >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		$sql = "SELECT * FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $id);

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->id = $obj->rowid;
				$this->rowid = $obj->rowid;
				$this->fk_dmm_module = $obj->fk_dmm_module;
				$this->module_id = $obj->module_id;
				$this->version_from = $obj->version_from;
				$this->version_to = $obj->version_to;
				$this->backup_path = $obj->backup_path;
				$this->backup_size = $obj->backup_size;
				$this->status = $obj->status;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Delete backup record and optionally the files
	 *
	 * @param  User $user       User deleting
	 * @param  bool $deleteFiles Also delete backup files from disk
	 * @return int               >0 if OK, <0 if KO
	 */
	public function delete($user, $deleteFiles = true)
	{
		if ($deleteFiles && !empty($this->backup_path) && is_dir($this->backup_path)) {
			// Only delete inside the backups root: a corrupted/crafted row must not be able
			// to drive a recursive delete outside DOL_DATA_ROOT/dolimodulemanager/backups/.
			$backupRoot = realpath(DOL_DATA_ROOT.'/dolimodulemanager/backups');
			$target = realpath($this->backup_path);
			if ($backupRoot !== false && $target !== false && strpos($target, $backupRoot.DIRECTORY_SEPARATOR) === 0) {
				dol_delete_dir_recursive($this->backup_path);
			} else {
				dol_syslog('DMMBackup::delete refused to delete out-of-root path '.$this->backup_path, LOG_WARNING);
			}
		}

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
	 * Restore this backup: copy backup_path back to /custom/{module_id}/
	 *
	 * @return array Result: ['success' => bool, 'message' => string]
	 */
	public function restore()
	{
		if (empty($this->backup_path) || !is_dir($this->backup_path)) {
			return array('success' => false, 'message' => 'Backup directory not found: '.$this->backup_path);
		}

		// Defense in depth: module_id drives a recursive delete/copy below, so re-validate
		// it here even though the install path already sanitizes it (a row could have been
		// written by another/future code path with a traversal payload).
		if (!function_exists('dmm_sanitize_module_id')) {
			dol_include_once('/dolimodulemanager/lib/dolimodulemanager.lib.php');
		}
		if (dmm_sanitize_module_id($this->module_id) !== $this->module_id) {
			return array('success' => false, 'message' => 'Invalid module id: '.$this->module_id);
		}

		$customDir = DOL_DOCUMENT_ROOT.'/custom/'.$this->module_id;

		// Atomic restore via rename swap: never leave the module absent on a failed copy.
		// 1. Copy backup into a staging dir; if that fails, the live module is untouched.
		// 2. Move the live module aside, move staging into place, then drop the old one.
		$stagingDir = $customDir.'.dmmrestore';
		$oldDir = $customDir.'.dmmold';

		// Clean up any leftovers from a previously interrupted restore
		if (is_dir($stagingDir)) {
			dol_delete_dir_recursive($stagingDir);
		}
		if (is_dir($oldDir)) {
			dol_delete_dir_recursive($oldDir);
		}

		$result = dolCopyDir($this->backup_path, $stagingDir, '0', 1);
		if ($result < 0) {
			dol_delete_dir_recursive($stagingDir);
			return array('success' => false, 'message' => 'Failed to stage backup copy for '.$customDir.'.'.$this->permissionHint(dirname($customDir)));
		}

		// Swap: move current aside (if present), promote staging, then remove the old copy.
		if (is_dir($customDir) && !@rename($customDir, $oldDir)) {
			dol_delete_dir_recursive($stagingDir);
			return array('success' => false, 'message' => 'Failed to move current module aside: '.$customDir.'.'.$this->permissionHint(dirname($customDir)));
		}
		if (!@rename($stagingDir, $customDir)) {
			// Roll back: restore the module we moved aside so we never leave it missing.
			if (is_dir($oldDir)) {
				@rename($oldDir, $customDir);
			}
			dol_delete_dir_recursive($stagingDir);
			return array('success' => false, 'message' => 'Failed to promote restored module into '.$customDir);
		}
		if (is_dir($oldDir)) {
			dol_delete_dir_recursive($oldDir);
		}

		// Update status
		$sql = "UPDATE ".$this->db->prefix().$this->table_element;
		$sql .= " SET status = 'restored'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		$this->db->query($sql);
		$this->status = 'restored';

		return array('success' => true, 'message' => 'Module '.$this->module_id.' restored to version '.$this->version_from);
	}

	/**
	 * Build a "why did the filesystem operation fail" hint for a directory, naming
	 * the PHP user and the exact chown/chmod fix — the usual cause of a failed
	 * restore is that the web user cannot write inside custom/.
	 *
	 * @param  string $dir Directory the operation needs to write into
	 * @return string      Human-readable hint (leading space), empty if $dir looks writable
	 */
	private function permissionHint($dir)
	{
		if (is_writable($dir)) {
			return ''; // Not a permission problem — avoid a misleading suggestion.
		}
		$phpUser = function_exists('dmm_get_php_user') ? dmm_get_php_user('the web user') : 'the web user';
		return ' PHP runs as "'.$phpUser.'" but cannot write to '.$dir.'.'
			.' Fix with: chown -R '.$phpUser.':'.$phpUser.' '.$dir.' && chmod -R u+w '.$dir;
	}

	/**
	 * Fetch all backups, optionally filtered by module
	 *
	 * @param  int $fk_module Filter by module rowid (0 = all)
	 * @return array          Array of DMMBackup objects
	 */
	public function fetchAll($fk_module = 0)
	{
		$backups = array();

		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		if ($fk_module > 0) {
			$sql .= " WHERE fk_dmm_module = ".((int) $fk_module);
		}
		$sql .= " ORDER BY date_creation DESC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$backup = new self($this->db);
				$backup->fetch($obj->rowid);
				$backups[] = $backup;
			}
		}

		return $backups;
	}

	/**
	 * Cleanup old backups based on retention settings
	 *
	 * @param  int $retentionDays  Max age in days (0 = no age limit)
	 * @param  int $retentionCount Max backups per module (0 = no count limit)
	 * @return int                 Number of backups removed
	 */
	public function cleanup($retentionDays = 0, $retentionCount = 0)
	{
		if ($retentionDays <= 0) {
			$retentionDays = (int) dmm_get_setting('backup_retention_days', 30);
		}
		if ($retentionCount <= 0) {
			$retentionCount = (int) dmm_get_setting('backup_retention_count', 5);
		}

		$removed = 0;

		// Remove by age (all statuses — prevents orphaned backups on disk)
		if ($retentionDays > 0) {
			$cutoff = dol_now('gmt') - ($retentionDays * 86400);
			$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
			$sql .= " WHERE date_creation < '".$this->db->idate($cutoff)."'";

			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$b = new self($this->db);
					$b->fetch($obj->rowid);
					$b->delete(null, true);
					$removed++;
				}
			}
		}

		// Remove by count per module
		if ($retentionCount > 0) {
			$sql = "SELECT DISTINCT fk_dmm_module FROM ".$this->db->prefix().$this->table_element;
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$sql2 = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
					$sql2 .= " WHERE fk_dmm_module = ".((int) $obj->fk_dmm_module);
					$sql2 .= " ORDER BY date_creation DESC";

					$resql2 = $this->db->query($sql2);
					if ($resql2) {
						$i = 0;
						while ($obj2 = $this->db->fetch_object($resql2)) {
							$i++;
							if ($i > $retentionCount) {
								$b = new self($this->db);
								$b->fetch($obj2->rowid);
								$b->delete(null, true);
								$removed++;
							}
						}
					}
				}
			}
		}

		return $removed;
	}
}
