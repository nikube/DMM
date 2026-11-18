<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/DmmbackSubdirHardeningTest.php
 * \ingroup dolimodulemanager
 * \brief   A monorepo subdir cannot leave the extracted archive, and a licence
 *          key goes to the most specific registered dmmback server.
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/fixtures/FakeDoliDB.php';

use PHPUnit\Framework\TestCase;

final class DmmbackSubdirHardeningTest extends TestCase
{
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		require_once __DIR__.'/../class/DMMClient.class.php';
		$this->root = sys_get_temp_dir().'/dmm_subdir_'.uniqid();
		mkdir($this->root.'/extract/wrapper/mods/good/core/modules', 0755, true);
		file_put_contents($this->root.'/extract/wrapper/mods/good/core/modules/modGood.class.php', '<?php');
		// A module-shaped directory outside the archive: must stay unreachable.
		mkdir($this->root.'/evil/core/modules', 0755, true);
		file_put_contents($this->root.'/evil/core/modules/modEvil.class.php', '<?php');
	}

	protected function tearDown(): void
	{
		exec('rm -rf '.escapeshellarg($this->root));
	}

	private function call($method, array $args, array $props = array())
	{
		$client = new DMMClient(new FakeMysqlDB(array()));
		foreach ($props as $name => $value) {
			$p = new ReflectionProperty('DMMClient', $name);
			$p->setAccessible(true);
			$p->setValue($client, $value);
		}
		$m = new ReflectionMethod('DMMClient', $method);
		$m->setAccessible(true);
		return $m->invokeArgs($client, $args);
	}

	public function testDeclaredSubdirIsFound()
	{
		$found = $this->call('findModuleRoot', array($this->root.'/extract', 'good', '/mods/./good/'));
		$this->assertSame(realpath($this->root.'/extract/wrapper/mods/good'), realpath((string) $found));
	}

	public function testSubdirCannotLeaveTheArchive()
	{
		$evil = realpath($this->root.'/evil');
		foreach (array('../../evil', '....//....//evil', '..\\..\\evil', 'mods/../../../evil') as $subdir) {
			$found = $this->call('findModuleRoot', array($this->root.'/extract', 'evil', $subdir));
			$this->assertNotSame($evil, $found === false ? false : realpath($found), $subdir);
		}
	}

	public function testMostSpecificDmmbackServerWins()
	{
		$tokens = array();
		foreach (array('ACME' => 'https://example.com', 'Zeta' => 'https://example.com/dmm') as $label => $server) {
			$t = new stdClass();
			$t->label = $label;
			$t->github_owner = $server;
			$tokens[] = $t;
		}
		$props = array('standalone' => true, 'dmmbackTokens' => $tokens);

		$this->assertSame('Zeta', $this->call('dmmbackTokenFor', array('https://example.com/dmm/mymod'), $props)->label);
		$this->assertSame('ACME', $this->call('dmmbackTokenFor', array('https://example.com/other'), $props)->label);
		$this->assertNull($this->call('dmmbackTokenFor', array('https://example.computer/x'), $props));
	}
}
