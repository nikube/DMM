<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/fixtures/FakeDoliDB.php
 * \ingroup dolimodulemanager
 * \brief   Minimal DoliDB stand-ins reproducing the mysqli / pgsql driver
 *          contract that DMMClient relies on (prefix, escape, query, num_rows,
 *          DDLListTables, database_name).
 */

/**
 * Common behaviour shared by both fake drivers.
 */
abstract class FakeDoliDBBase
{
	/** @var string[] Existing table names (with prefix), e.g. "llx_dmm_token". */
	protected $tables;

	/** @var string Database name (used by DDLListTables on MySQL). */
	public $database_name = 'doli_test';

	/**
	 * @param string[] $tables List of existing tables, with prefix.
	 */
	public function __construct(array $tables)
	{
		$this->tables = $tables;
	}

	public function prefix()
	{
		return 'llx_';
	}

	public function escape($value)
	{
		return str_replace("'", "\\'", (string) $value);
	}

	/**
	 * Mirror of Dolibarr's num_rows(): our fake "resultset" is a plain array.
	 *
	 * @param  array|bool $resql
	 * @return int
	 */
	public function num_rows($resql)
	{
		return is_array($resql) ? count($resql) : 0;
	}

	/**
	 * Portable table listing — present on every real DoliDB driver.
	 * Honours the LIKE-style $table filter the same way the real drivers do
	 * (exact name here, since DMMClient passes the full table name).
	 *
	 * @param  string $database Unused by the SQL-standard path (kept for parity).
	 * @param  string $table    Table filter (full name, no wildcard here).
	 * @return string[]
	 */
	public function DDLListTables($database, $table = '')
	{
		if ($table === '') {
			return $this->tables;
		}
		$out = array();
		foreach ($this->tables as $t) {
			if (strcasecmp($t, $table) === 0) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Driver-specific query handler.
	 *
	 * @param  string     $sql
	 * @return array|bool Fake resultset (array of rows) or false on failure.
	 */
	abstract public function query($sql);
}

/**
 * MySQL/MariaDB driver: understands "SHOW TABLES LIKE" and information_schema.
 */
final class FakeMysqlDB extends FakeDoliDBBase
{
	public function query($sql)
	{
		if (preg_match("/^SHOW TABLES LIKE '([^']+)'/i", $sql, $m)) {
			return in_array($m[1], $this->tables, true) ? array(array($m[1])) : array();
		}
		if (preg_match("/information_schema\.tables.*table_name = '([^']+)'/is", $sql, $m)) {
			return in_array($m[1], $this->tables, true) ? array(array($m[1])) : array();
		}
		return false;
	}
}

/**
 * PostgreSQL driver: the MySQL-only "SHOW TABLES" syntax errors out (returns
 * false), exactly like the real pgsql driver. information_schema works.
 */
final class FakePgsqlDB extends FakeDoliDBBase
{
	public function query($sql)
	{
		if (preg_match('/^SHOW TABLES/i', $sql)) {
			// Real PostgreSQL: syntax error near "TABLES" -> query() returns false.
			return false;
		}
		if (preg_match("/information_schema\.tables.*table_name = '([^']+)'/is", $sql, $m)) {
			return in_array($m[1], $this->tables, true) ? array(array($m[1])) : array();
		}
		return false;
	}
}
