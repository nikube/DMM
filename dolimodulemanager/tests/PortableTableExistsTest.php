<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/PortableTableExistsTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression test for the PostgreSQL portability of DMMClient.
 *
 * Bug (DMM <= 1.9.2): DMMClient::tableExists() used "SHOW TABLES LIKE '...'",
 * a MySQL-only statement. On PostgreSQL the query failed, query() returned
 * false, so $standalone stayed false and importFromHub() bailed out with
 * "Hub import requires standalone mode" — the registry stayed empty even
 * though the DMM tables existed.
 *
 * This test stands in fake DoliDB drivers that reproduce the contract of the
 * real mysqli / pgsql drivers (notably: a PostgreSQL driver that returns false
 * for the MySQL-only "SHOW TABLES" query but answers DDLListTables() correctly).
 * It asserts DMMClient detects standalone mode on BOTH drivers.
 *
 * Pure PHP (no live DB) so it runs in CI under php 7.4+ with just `phpunit`.
 * For a full end-to-end check against a real PostgreSQL instance, see
 * tests/README-pgsql.md.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/fixtures/FakeDoliDB.php';

final class PortableTableExistsTest extends TestCase
{
	/**
	 * Load DMMClient once, in isolation from a full Dolibarr bootstrap.
	 */
	public static function setUpBeforeClass(): void
	{
		if (!class_exists('DMMClient')) {
			require_once __DIR__.'/../class/DMMClient.class.php';
		}
	}

	/**
	 * Read the private $standalone flag set by the constructor.
	 */
	private function standaloneFlag(DMMClient $client): bool
	{
		$ref = new ReflectionProperty(DMMClient::class, 'standalone');
		return (bool) $ref->getValue($client);
	}

	public function testStandaloneDetectedOnMysql(): void
	{
		$db = new FakeMysqlDB(array('llx_dmm_token', 'llx_dmm_module'));
		$client = new DMMClient($db);
		$this->assertTrue(
			$this->standaloneFlag($client),
			'DMM tables present on MySQL -> standalone mode must be on.'
		);
	}

	public function testStandaloneDetectedOnPostgres(): void
	{
		// The PG driver intentionally fails the MySQL-only "SHOW TABLES" query.
		$db = new FakePgsqlDB(array('llx_dmm_token', 'llx_dmm_module'));
		$client = new DMMClient($db);
		$this->assertTrue(
			$this->standaloneFlag($client),
			'Regression: DMM tables present on PostgreSQL must still enable '
			.'standalone mode (was broken by "SHOW TABLES LIKE").'
		);

		// And prove the bug would have been caught: the old code path (a raw
		// "SHOW TABLES" query) returns false on this driver.
		$this->assertFalse(
			$db->query("SHOW TABLES LIKE 'llx_dmm_token'"),
			'Sanity: the PG fake must reject the MySQL-only statement.'
		);
	}

	public function testEmbeddedModeWhenTablesAbsentOnPostgres(): void
	{
		$db = new FakePgsqlDB(array()); // no DMM tables
		$client = new DMMClient($db);
		$this->assertFalse(
			$this->standaloneFlag($client),
			'No DMM tables -> embedded (non-standalone) mode on PostgreSQL.'
		);
	}

	public function testEmbeddedModeWhenTablesAbsentOnMysql(): void
	{
		$db = new FakeMysqlDB(array());
		$client = new DMMClient($db);
		$this->assertFalse($this->standaloneFlag($client));
	}
}
