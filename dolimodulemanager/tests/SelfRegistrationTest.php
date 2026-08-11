<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/SelfRegistrationTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression test for DMM registering its own module row.
 *
 * Bug (DMM <= 2.1.0): DMM only ever got a registry row as a side effect of the
 * first-run hub import, because the default hub happens to list nikube/DMM. The
 * dashboard adds DMM back to the "managed" list from that row, so with no hub
 * configured, an unreachable one, or a first run that imported nothing, DMM was
 * installed and enabled yet absent from the very list it draws.
 *
 * Surfaced on a Dolibarr 24 instance where core commit c4de36f7a59 had commented
 * out the data*.sql loader, so data.sql never seeded hub_urls: no hub, no import,
 * no row, no DMM in the list.
 *
 * ensureSelfRegistered() writes that row locally instead. It must create it once,
 * stay idempotent across the page loads that call it, and never overwrite a row
 * the user has repointed at a fork.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

require_once __DIR__.'/../class/DMMClient.class.php';

/**
 * DoliDB double recording what registerScannedModule() would write.
 *
 * Only the calls ensureSelfRegistered() reaches matter: the duplicate-source
 * lookups (which must find nothing on a clean registry) and the INSERT.
 */
class SelfRegFakeDB
{
	/** @var array<int,string> Every SQL statement seen, in order */
	public $queries = array();

	/** @var int Rows the next duplicate-check SELECT should report */
	public $duplicateRows = 0;

	public function prefix()
	{
		return 'llx_';
	}

	public function escape($s)
	{
		return $s;
	}

	public function query($sql)
	{
		$this->queries[] = $sql;
		return true;
	}

	public function num_rows($r)
	{
		return $this->duplicateRows;
	}

	public function free($r = null)
	{
	}

	/**
	 * A row shaped like llx_dmm_module, returned when duplicateRows says one
	 * exists. DMMModule::loadFromObject() reads every column unguarded, so the
	 * double has to carry them all.
	 */
	public function fetch_object($r)
	{
		return (object) array(
			'rowid' => 7,
			'module_id' => 'dolimodulemanager',
			'name' => 'DoliModuleManager',
			'description' => null,
			'author' => null,
			'license' => null,
			'url' => null,
			'github_repo' => 'someone/DMM-fork',
			'fk_dmm_token' => null,
			'installed_version' => '2.1.0',
			'installed' => 1,
			'cache_latest_version' => null,
			'cache_latest_compatible' => null,
			'cache_changelog' => null,
			'cache_manifest_json' => null,
			'cache_etag' => null,
			'cache_last_check' => null,
			'cache_last_error' => null,
			'date_creation' => null,
		);
	}

	public function jdate($d)
	{
		return null;
	}

	public function begin()
	{
		return 1;
	}

	public function commit()
	{
		return 1;
	}

	public function rollback()
	{
		return 1;
	}

	public function idate($d)
	{
		return '2026-01-01 00:00:00';
	}

	public function lasterror()
	{
		return '';
	}
}

final class SelfRegistrationTest extends TestCase
{
	/**
	 * The identity DMM registers itself under. Hardcoded on purpose: dmm.json sits
	 * at the repository root, outside dolimodulemanager/, so it never ships inside
	 * the released zip and cannot be read from an installed copy.
	 */
	public function testSelfConstantsMatchTheRealModule(): void
	{
		$this->assertSame('dolimodulemanager', DMMClient::SELF_MODULE_ID);
		$this->assertSame('nikube/DMM', DMMClient::SELF_REPO);
	}

	/**
	 * The module directory name must equal SELF_MODULE_ID, since Dolibarr resolves
	 * a module's includes through custom/{module_id}: a mismatch would register a
	 * row pointing at a directory that does not exist.
	 */
	public function testSelfModuleIdMatchesDirectoryName(): void
	{
		$moduleDir = basename(dirname(__DIR__));
		$this->assertSame($moduleDir, DMMClient::SELF_MODULE_ID);
	}

	/**
	 * SELF_REPO must agree with the repository dmm.json advertises, otherwise a
	 * self-registered row and a hub-imported one would describe two sources for
	 * one module and collide on the unique (module_id, github_repo) key.
	 */
	public function testSelfRepoMatchesManifest(): void
	{
		$manifestPath = dirname(__DIR__, 2).'/dmm.json';
		if (!is_file($manifestPath)) {
			$this->markTestSkipped('dmm.json is only present in a repository checkout');
		}

		$manifest = json_decode((string) file_get_contents($manifestPath), true);
		$this->assertIsArray($manifest, 'dmm.json must be valid JSON');
		$this->assertSame(DMMClient::SELF_REPO, $manifest['repository'] ?? null);
		$this->assertSame(DMMClient::SELF_MODULE_ID, $manifest['module_id'] ?? null);
	}

	/**
	 * Without the DMM tables there is no registry to write to, so the call must be
	 * a no-op rather than an error: the dashboard runs it on every load.
	 */
	public function testNonStandaloneIsANoOp(): void
	{
		$db = new SelfRegFakeDB();
		$client = new DMMClient($db, false);

		$this->assertFalse($client->ensureSelfRegistered());

		// The constructor probes for the DMM tables, so "no queries at all" would be
		// the wrong bar. What matters is that nothing is written.
		$writes = array_filter($db->queries, function ($sql) {
			return (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql);
		});
		$this->assertSame(array(), $writes, 'non-standalone must not write to the registry');
	}

	/**
	 * An existing row wins, whatever it points at. This is what protects a user who
	 * repointed DMM at their own fork from having it silently reset to nikube/DMM
	 * on the next dashboard load.
	 */
	public function testExistingRowIsLeftAlone(): void
	{
		$db = new SelfRegFakeDB();
		$db->duplicateRows = 1; // fetch() finds a row for dolimodulemanager
		$client = new DMMClient($db, true);

		$this->assertFalse(
			$client->ensureSelfRegistered(),
			'a registry row that already exists must not be recreated'
		);

		$inserts = array_filter($db->queries, function ($sql) {
			return stripos($sql, 'INSERT INTO') !== false;
		});
		$this->assertSame(array(), $inserts, 'no row may be written when one exists');
	}
}
