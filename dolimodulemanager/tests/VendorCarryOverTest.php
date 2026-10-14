<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/VendorCarryOverTest.php
 * \ingroup dolimodulemanager
 * \brief   An update from a git archive must keep the installed composer vendor/.
 *
 * banking4dolibarr 14.0.103 -> 14.0.114 on Serendip (14/10/2026): the GitLab
 * archive gitignores vendor/, the swap dropped the installed one, and the bank
 * page fataled on "Class GuzzleHttp\Client not found".
 */

require_once __DIR__.'/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class VendorCarryOverTest extends TestCase
{
	/** @var string */
	private $root;

	protected function setUp(): void
	{
		require_once __DIR__.'/../class/DMMClient.class.php';
		$this->root = sys_get_temp_dir().'/dmm_vendor_'.uniqid();
		mkdir($this->root.'/old/vendor/guzzlehttp', 0755, true);
		file_put_contents($this->root.'/old/vendor/autoload.php', '<?php');
		file_put_contents($this->root.'/old/vendor/guzzlehttp/Client.php', '<?php');
		mkdir($this->root.'/new', 0755, true);
	}

	protected function tearDown(): void
	{
		exec('rm -rf '.escapeshellarg($this->root));
	}

	private function carry()
	{
		$m = new ReflectionMethod('DMMClient', 'carryOverVendor');
		$m->setAccessible(true);
		return $m->invoke(new DMMClient(new VendorCarryOverFakeDB()), $this->root.'/old', $this->root.'/new');
	}

	public function testVendorIsCarriedWhenArchiveDeclaresComposerButShipsNone()
	{
		file_put_contents($this->root.'/new/composer.json', '{}');

		$this->assertTrue($this->carry());
		$this->assertFileExists($this->root.'/new/vendor/guzzlehttp/Client.php');
		$this->assertFileExists($this->root.'/new/vendor/autoload.php');
	}

	public function testNothingHappensWithoutComposerJson()
	{
		$this->assertFalse($this->carry());
		$this->assertDirectoryDoesNotExist($this->root.'/new/vendor');
	}

	public function testShippedVendorWins()
	{
		file_put_contents($this->root.'/new/composer.json', '{}');
		mkdir($this->root.'/new/vendor', 0755, true);
		file_put_contents($this->root.'/new/vendor/autoload.php', '<?php // shipped');

		$this->assertFalse($this->carry());
		$this->assertSame('<?php // shipped', file_get_contents($this->root.'/new/vendor/autoload.php'));
		$this->assertFileDoesNotExist($this->root.'/new/vendor/guzzlehttp/Client.php');
	}
}

/** Minimal DoliDB stand-in: the constructor wants one, the helper never touches it. */
class VendorCarryOverFakeDB
{
	public $type = 'mysqli';

	public function prefix()
	{
		return 'llx_';
	}

	public function escape($s)
	{
		return addslashes((string) $s);
	}

	public function query($sql)
	{
		return false;
	}

	public function num_rows($r)
	{
		return 0;
	}

	public function fetch_object($r)
	{
		return null;
	}

	public function affected_rows($r)
	{
		return 0;
	}
}
