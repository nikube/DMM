<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

/** Regression coverage for the staging/swap shared by DoliStore installs. */
final class DolistoreDeploymentTest extends TestCase
{
	/** @var string */
	private $base;
	/** @var DMMClient */
	private $client;
	/** @var ReflectionMethod */
	private $deploy;

	public static function setUpBeforeClass(): void
	{
		require_once __DIR__.'/../class/DMMClient.class.php';
	}

	protected function setUp(): void
	{
		$this->base = DOL_DOCUMENT_ROOT.'/custom/zzdmmdolistore';
		$this->rmrf($this->base);
		@mkdir($this->base, 0755, true);
		$this->client = new DMMClient(new DmmDeploymentFakeDB());
		$this->deploy = new ReflectionMethod(DMMClient::class, 'deployModuleDirectory');
	}

	protected function tearDown(): void
	{
		$this->rmrf($this->base);
	}

	public function testFreshInstallPromotesCompleteStagingDirectory(): void
	{
		$source = $this->makeModule('source', '1.0.0', array('payload.txt' => 'new'));
		$target = $this->base.'/live';

		$result = $this->deploy->invoke($this->client, $source, $target, false);

		$this->assertTrue($result['success']);
		$this->assertSame('new', file_get_contents($target.'/payload.txt'));
		$this->assertDirectoryDoesNotExist($target.'.dmmnew');
		$this->assertDirectoryDoesNotExist($target.'.dmmold');
	}

	public function testUpdateReplacesDirectoryAndDropsStaleFiles(): void
	{
		$target = $this->makeModule('live', '1.0.0', array('stale.txt' => 'old'));
		$source = $this->makeModule('source', '2.0.0', array('current.txt' => 'new'));

		$result = $this->deploy->invoke($this->client, $source, $target, true);

		$this->assertTrue($result['success']);
		$this->assertFileDoesNotExist($target.'/stale.txt');
		$this->assertSame('new', file_get_contents($target.'/current.txt'));
		$this->assertDirectoryDoesNotExist($target.'.dmmold');
	}

	public function testInvalidStagingNeverReplacesLiveModule(): void
	{
		$target = $this->makeModule('live', '1.0.0', array('keep.txt' => 'live'));
		$source = $this->base.'/source';
		@mkdir($source, 0755, true);
		file_put_contents($source.'/payload.txt', 'incomplete');

		$result = $this->deploy->invoke($this->client, $source, $target, true);

		$this->assertFalse($result['success']);
		$this->assertSame('live', file_get_contents($target.'/keep.txt'));
		$this->assertDirectoryDoesNotExist($target.'.dmmnew');
	}

	private function makeModule($name, $version, array $files)
	{
		$dir = $this->base.'/'.$name;
		@mkdir($dir.'/core/modules', 0755, true);
		file_put_contents($dir.'/core/modules/modZzDmm.class.php', "<?php\nclass modZzDmm { public \$version = '".$version."'; }\n");
		foreach ($files as $path => $content) {
			file_put_contents($dir.'/'.$path, $content);
		}
		return $dir;
	}

	private function rmrf($path)
	{
		if (is_dir($path)) {
			foreach (array_diff(scandir($path), array('.', '..')) as $item) {
				$this->rmrf($path.'/'.$item);
			}
			@rmdir($path);
		} elseif (file_exists($path)) {
			@unlink($path);
		}
	}
}

/** Minimal DB double that keeps DMMClient in embedded mode. */
final class DmmDeploymentFakeDB
{
	public $database_name = 'test';

	public function prefix()
	{
		return 'llx_';
	}

	public function DDLListTables($database, $table = '')
	{
		return array();
	}
}
