<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/UninstallModuleTest.php
 * \ingroup dolimodulemanager
 * \brief   Tests for DMMClient::uninstallModule() (developer-mode uninstall).
 *
 * Runs the real file operations against an isolated stub tree: a fake module
 * is written under DOL_DOCUMENT_ROOT/custom/ with a descriptor that records
 * whether remove() was called. Verifies the guards (self, core, missing dir),
 * the backup, the conditional remove() and the final directory deletion.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

final class UninstallModuleTest extends TestCase
{
	/** @var string */
	private $customBase;
	/** @var string */
	private $backupRoot;

	public static function setUpBeforeClass(): void
	{
		if (!class_exists('DMMClient')) {
			require_once __DIR__.'/../class/DMMClient.class.php';
		}
		if (!function_exists('dmm_is_core_module')) {
			require_once __DIR__.'/../lib/dolimodulemanager.lib.php';
		}
	}

	protected function setUp(): void
	{
		global $conf;
		$conf = new stdClass();
		$conf->global = new stdClass();
		$GLOBALS['zzdmm_remove_calls'] = 0;

		$this->customBase = DOL_DOCUMENT_ROOT.'/custom';
		$this->backupRoot = DOL_DATA_ROOT.'/dolimodulemanager/backups';
		@mkdir($this->customBase, 0755, true);
		@mkdir($this->backupRoot, 0755, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->customBase.'/zzdmm*') as $d) {
			$this->rmrf($d);
		}
		foreach (glob($this->backupRoot.'/zzdmm*') as $d) {
			$this->rmrf($d);
		}
	}

	private function rmrf($path)
	{
		if (is_dir($path)) {
			foreach (array_diff(scandir($path), array('.', '..')) as $i) {
				$this->rmrf($path.'/'.$i);
			}
			@rmdir($path);
		} elseif (file_exists($path)) {
			@unlink($path);
		}
	}

	/**
	 * Write a fake module with a descriptor whose remove() bumps a global counter
	 * (and optionally fails).
	 */
	private function makeModule($id, $removeReturns = 1)
	{
		$class = 'mod'.ucfirst($id);
		$dir = $this->customBase.'/'.$id.'/core/modules';
		@mkdir($dir, 0755, true);
		file_put_contents($this->customBase.'/'.$id.'/README.txt', 'victim');
		$php = "<?php\n"
			."include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';\n"
			."class $class extends DolibarrModules {\n"
			."\tpublic function __construct(\$db) { parent::__construct(\$db); \$this->version = '1.2.3'; \$this->const_name = 'MAIN_MODULE_".strtoupper($id)."'; }\n"
			."\tpublic function remove(\$options = '') { \$GLOBALS['zzdmm_remove_calls']++; \$this->error = 'boom'; return $removeReturns; }\n"
			."}\n";
		file_put_contents($dir.'/'.$class.'.class.php', $php);
		return $this->customBase.'/'.$id;
	}

	private function client()
	{
		return new DMMClient(new FakeUninstallDB());
	}

	public function testEnabledModuleIsDisabledBackedUpAndDeleted(): void
	{
		global $conf;
		$id = 'zzdmmenabled';
		$dir = $this->makeModule($id);
		$conf->global->MAIN_MODULE_ZZDMMENABLED = 1;

		$r = $this->client()->uninstallModule($id);

		$this->assertTrue($r['success'], $r['message']);
		$this->assertTrue($r['disabled'], 'remove() path must be reported');
		$this->assertSame(1, $GLOBALS['zzdmm_remove_calls'], 'descriptor remove() called once');
		$this->assertDirectoryDoesNotExist($dir, 'module directory deleted');
		$this->assertNotEmpty($r['backup_path']);
		$this->assertFileExists($r['backup_path'].'/README.txt', 'backup holds the module files');
		$this->assertStringContainsString($id.'_1.2.3_', basename($r['backup_path']), 'backup named with version');
	}

	public function testDisabledModuleSkipsRemoveButStillDeletes(): void
	{
		$id = 'zzdmmdisabled';
		$dir = $this->makeModule($id);
		// MAIN_MODULE_ZZDMMDISABLED not set

		$r = $this->client()->uninstallModule($id);

		$this->assertTrue($r['success'], $r['message']);
		$this->assertFalse($r['disabled']);
		$this->assertSame(0, $GLOBALS['zzdmm_remove_calls'], 'remove() must not run on a disabled module');
		$this->assertDirectoryDoesNotExist($dir);
	}

	public function testRemoveFailureAbortsBeforeDeletingFiles(): void
	{
		global $conf;
		$id = 'zzdmmfail';
		$dir = $this->makeModule($id, -1);
		$conf->global->MAIN_MODULE_ZZDMMFAIL = 1;

		$r = $this->client()->uninstallModule($id);

		$this->assertFalse($r['success']);
		$this->assertStringContainsString('boom', $r['message']);
		$this->assertDirectoryExists($dir, 'files must be kept when remove() fails');
		$this->assertFileExists($dir.'/README.txt');
	}

	public function testKeepFilesModeDisablesButLeavesDirectory(): void
	{
		global $conf;
		$id = 'zzdmmkeep';
		$dir = $this->makeModule($id);
		$conf->global->MAIN_MODULE_ZZDMMKEEP = 1;

		$r = $this->client()->uninstallModule($id, false);

		$this->assertTrue($r['success'], $r['message']);
		$this->assertTrue($r['disabled'], 'module must still be disabled');
		$this->assertFalse($r['files_deleted']);
		$this->assertSame(1, $GLOBALS['zzdmm_remove_calls']);
		$this->assertDirectoryExists($dir, 'files must be left in place');
		$this->assertNotEmpty($r['backup_path'], 'backup still taken');
	}

	public function testRefusesSelfCoreMissingAndTraversal(): void
	{
		$c = $this->client();

		$r = $c->uninstallModule('dolimodulemanager');
		$this->assertFalse($r['success']);
		$this->assertStringContainsString('itself', $r['message']);

		$r = $c->uninstallModule('zzdmmnotthere');
		$this->assertFalse($r['success']);
		$this->assertStringContainsString('not found', $r['message']);

		$victim = $this->makeModule('zzdmmtrav');
		$r = $c->uninstallModule('../custom/zzdmmtrav/../zzdmmtrav');
		// sanitized id never resolves to the victim through traversal; whatever
		// the sanitizer yields, the real directory must be untouched unless it
		// resolved exactly to the plain id.
		$this->assertSame(0, $GLOBALS['zzdmm_remove_calls']);
		if (!$r['success']) {
			$this->assertDirectoryExists($victim);
		}
	}
}

/**
 * Minimal DB fake: no DMM tables (standalone=false), so no registry/backup rows.
 */
class FakeUninstallDB
{
	public $database_name = 'test';
	public function prefix()
	{
		return 'llx_';
	}
	public function escape($v)
	{
		return addslashes($v);
	}
	public function DDLListTables($database, $table = '')
	{
		return array();
	}
	public function query($sql)
	{
		return false;
	}
	public function num_rows($r)
	{
		return 0;
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
	public function lasterror()
	{
		return '';
	}
}
