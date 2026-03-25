<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/DMMToken.class.php
 * \ingroup dolimodulemanager
 * \brief   CRUD class for GitHub token management
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class DMMToken - GitHub access token management
 */
class DMMToken extends CommonObject
{
	/** @var string Module name */
	public $module = 'dolimodulemanager';

	/** @var string Element identifier */
	public $element = 'dmmtoken';

	/** @var string Table name without prefix */
	public $table_element = 'dmm_token';

	/** @var string Icon */
	public $picto = 'fa-key';

	/** @var int No extrafields */
	public $isextrafieldmanaged = 0;

	/** @var int No multicompany */
	public $ismultientitymanaged = 0;

	/**
	 * @var array Field definitions
	 */
	public $fields = array(
		'rowid'          => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 1, 'index' => 1),
		'label'          => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 10, 'searchall' => 1),
		'token'          => array('type' => 'text', 'label' => 'Token', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'position' => 20),
		'github_owner'   => array('type' => 'varchar(255)', 'label' => 'GitHubOwner', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 30),
		'token_type'     => array('type' => 'varchar(20)', 'label' => 'TokenType', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 40, 'default' => 'pat'),
		'status'         => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 50, 'default' => 1),
		'last_validated' => array('type' => 'datetime', 'label' => 'LastValidated', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 60),
		'note'           => array('type' => 'text', 'label' => 'Note', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 70),
		'date_creation'  => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 500),
		'tms'            => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -1, 'notnull' => 0, 'position' => 501),
		'fk_user_creat'  => array('type' => 'integer', 'label' => 'UserCreation', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 510),
		'fk_user_modif'  => array('type' => 'integer', 'label' => 'UserModification', 'enabled' => 1, 'visible' => -1, 'notnull' => 0, 'position' => 511),
	);

	/** @var int */
	public $rowid;
	/** @var string */
	public $label;
	/** @var string Encrypted token */
	public $token;
	/** @var string|null */
	public $github_owner;
	/** @var string */
	public $token_type;
	/** @var int */
	public $status;
	/** @var string|null */
	public $last_validated;
	/** @var string|null */
	public $note;
	/** @var string */
	public $date_creation;
	/** @var int */
	public $fk_user_creat;
	/** @var int|null */
	public $fk_user_modif;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;
		$this->db = $db;
	}

	/**
	 * Create token in database. Encrypts the token value.
	 *
	 * @param  User $user      User creating
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function create($user, $notrigger = false)
	{
		if (!empty($this->token) && strpos($this->token, 'dolcrypt:') !== 0) {
			$this->token = dolEncrypt($this->token);
		}

		$this->date_creation = dol_now('gmt');
		$this->fk_user_creat = $user->id;

		$sql = "INSERT INTO ".$this->db->prefix().$this->table_element." (";
		$sql .= "label, token, github_owner, token_type, status, note, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= "'".$this->db->escape($this->label)."'";
		$sql .= ", '".$this->db->escape($this->token)."'";
		$sql .= ", ".($this->github_owner ? "'".$this->db->escape($this->github_owner)."'" : "NULL");
		$sql .= ", '".$this->db->escape($this->token_type ?: 'pat')."'";
		$sql .= ", ".((int) ($this->status ?? 1));
		$sql .= ", ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", '".$this->db->idate($this->date_creation)."'";
		$sql .= ", ".((int) $this->fk_user_creat);
		$sql .= ")";

		$this->db->begin();
		$resql = $this->db->query($sql);
		if ($resql) {
			$this->id = $this->db->last_insert_id($this->db->prefix().$this->table_element);
			$this->rowid = $this->id;
			$this->db->commit();
			return $this->id;
		} else {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Load token from database
	 *
	 * @param  int    $id   Row ID
	 * @param  string $ref  Not used
	 * @return int          >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id, $ref = '')
	{
		$sql = "SELECT rowid, label, token, github_owner, token_type, status, last_validated, note, date_creation, tms, fk_user_creat, fk_user_modif";
		$sql .= " FROM ".$this->db->prefix().$this->table_element;
		$sql .= " WHERE rowid = ".((int) $id);

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql)) {
				$obj = $this->db->fetch_object($resql);
				$this->id = $obj->rowid;
				$this->rowid = $obj->rowid;
				$this->label = $obj->label;
				$this->token = $obj->token;
				$this->github_owner = $obj->github_owner;
				$this->token_type = $obj->token_type;
				$this->status = $obj->status;
				$this->last_validated = $this->db->jdate($obj->last_validated);
				$this->note = $obj->note;
				$this->date_creation = $this->db->jdate($obj->date_creation);
				$this->fk_user_creat = $obj->fk_user_creat;
				$this->fk_user_modif = $obj->fk_user_modif;
				return 1;
			}
			return 0;
		}
		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Update token in database
	 *
	 * @param  User $user      User modifying
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function update($user, $notrigger = false)
	{
		$this->fk_user_modif = $user->id;

		$sql = "UPDATE ".$this->db->prefix().$this->table_element." SET";
		$sql .= " label = '".$this->db->escape($this->label)."'";
		// Only update token if a new plaintext value was explicitly set (not already encrypted)
		// Skip update if token is null (means "keep existing"), but do NOT skip on empty string
		if ($this->token !== null && strpos($this->token, 'dolcrypt:') !== 0) {
			if ($this->token === '') {
				// Explicitly cleared — store empty encrypted string
				$sql .= ", token = '".$this->db->escape(dolEncrypt(''))."'";
			} else {
				$sql .= ", token = '".$this->db->escape(dolEncrypt($this->token))."'";
			}
		}
		$sql .= ", github_owner = ".($this->github_owner ? "'".$this->db->escape($this->github_owner)."'" : "NULL");
		$sql .= ", token_type = '".$this->db->escape($this->token_type ?: 'pat')."'";
		$sql .= ", status = ".((int) $this->status);
		$sql .= ", note = ".($this->note ? "'".$this->db->escape($this->note)."'" : "NULL");
		$sql .= ", fk_user_modif = ".((int) $this->fk_user_modif);
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
	 * Delete token from database
	 *
	 * @param  User $user      User deleting
	 * @param  bool $notrigger Disable triggers
	 * @return int             >0 if OK, <0 if KO
	 */
	public function delete($user, $notrigger = false)
	{
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
	 * Get decrypted token value
	 *
	 * @return string Plaintext token
	 */
	public function getDecryptedToken()
	{
		return dolDecrypt($this->token);
	}

	/**
	 * Get masked token for display: ghp_xxxx...xxxx
	 *
	 * @return string Masked token
	 */
	public function getMaskedToken()
	{
		$plain = $this->getDecryptedToken();
		if (strlen($plain) <= 11) {
			return str_repeat('*', strlen($plain));
		}
		return substr($plain, 0, 7).'...'.substr($plain, -4);
	}

	/**
	 * Validate token by calling GitHub API /rate_limit
	 *
	 * @return bool True if token is valid
	 */
	public function validate()
	{
		$plain = $this->getDecryptedToken();
		if (empty($plain)) {
			return false;
		}

		$ch = curl_init('https://api.github.com/rate_limit');
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array(
				'Authorization: Bearer '.$plain,
				'User-Agent: DMM/1.0',
				'Accept: application/vnd.github+json',
			),
			CURLOPT_TIMEOUT => 15,
		));
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$valid = ($httpCode === 200);

		// Update last_validated timestamp
		if ($valid) {
			$sql = "UPDATE ".$this->db->prefix().$this->table_element;
			$sql .= " SET last_validated = '".$this->db->idate(dol_now('gmt'))."'";
			$sql .= " WHERE rowid = ".((int) $this->id);
			$this->db->query($sql);
			$this->last_validated = dol_now('gmt');
		}

		return $valid;
	}

	/**
	 * Fetch all tokens
	 *
	 * @param  int   $activeOnly If 1, only active tokens
	 * @return array             Array of DMMToken objects
	 */
	public function fetchAll($activeOnly = 0)
	{
		$tokens = array();

		$sql = "SELECT rowid FROM ".$this->db->prefix().$this->table_element;
		if ($activeOnly) {
			$sql .= " WHERE status = 1";
		}
		$sql .= " ORDER BY label ASC";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$token = new self($this->db);
				$token->fetch($obj->rowid);
				$tokens[] = $token;
			}
		}

		return $tokens;
	}
}
