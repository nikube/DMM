<?php
/* Copyright (C) 2026 DMM Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    tests/RestoreSwapTest.php
 * \ingroup dolimodulemanager
 * \brief   Regression test for the atomic restore in DMMBackup::restore().
 *
 * Bug (DMM <= 1.10.3): restore() deleted the live module directory BEFORE copying
 * the backup back. A failed copy left the module missing entirely — the worst
 * outcome for the one operation you run precisely when something already broke.
 * delete() also fed a DB-sourced backup_path straight to a recursive delete.
 *
 * These tests run the real file operations (the test stub for files.lib.php uses
 * genuine filesystem calls) against isolated temp directories, so they verify the
 * staging + rename-swap behaviour without a live Dolibarr. Pure PHP, CI-friendly.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';

final class RestoreSwapTest extends TestCase
{
	/** @var string */
	private $customBase;
	/** @var string */
	private $backupRoot;

	public static function setUpBeforeClass(): void
	{
		if (!class_exists('DMMBackup')) {
			require_once __DIR__.'/../class/DMMBackup.class.php';
		}
	}

	protected function setUp(): void
	{
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

	private function makeBackup($moduleId, array $files)
	{
		$dir = $this->backupRoot.'/'.$moduleId.'_bak';
		@mkdir($dir, 0755, true);
		foreach ($files as $name => $content) {
			file_put_contents($dir.'/'.$name, $content);
		}
		$b = new DMMBackup(new FakeNoopDB());
		$b->module_id = $moduleId;
		$b->backup_path = $dir;
		$b->version_from = '1.0';
		return $b;
	}

	public function testNominalRestoreReplacesContentAndKeepsModulePresent(): void
	{
		$id = 'zzdmmnominal';
		$custom = $this->customBase.'/'.$id;
		@mkdir($custom, 0755, true);
		file_put_contents($custom.'/marker.txt', 'BROKEN');   // current (broken)

		$b = $this->makeBackup($id, array('marker.txt' => 'GOOD', 'extra.txt' => 'x'));
		$r = $b->restore();

		$this->assertTrue($r['success'], 'restore should succeed');
		$this->assertDirectoryExists($custom, 'module dir must stay present');
		$this->assertSame('GOOD', trim(file_get_contents($custom.'/marker.txt')), 'content replaced by backup');
		$this->assertFileExists($custom.'/extra.txt', 'backup-only file restored');
		$this->assertDirectoryDoesNotExist($custom.'.dmmrestore', 'staging cleaned up');
		$this->assertDirectoryDoesNotExist($custom.'.dmmold', 'old copy cleaned up');
	}

	public function testRestoreRefusesTraversalModuleId(): void
	{
		$b = $this->makeBackup('zzdmmtrav', array('a' => 'b'));
		$b->module_id = '../evil';               // traversal payload
		$r = $b->restore();
		$this->assertFalse($r['success'], 'traversal module_id must be refused');
	}

	public function testRestoreLeavesModuleIntactWhenBackupMissing(): void
	{
		$id = 'zzdmmnobak';
		$custom = $this->customBase.'/'.$id;
		@mkdir($custom, 0755, true);
		file_put_contents($custom.'/keep.txt', 'LIVE');

		$b = new DMMBackup(new FakeNoopDB());
		$b->module_id = $id;
		$b->backup_path = $this->backupRoot.'/does_not_exist';
		$r = $b->restore();

		$this->assertFalse($r['success'], 'restore must fail on missing backup');
		$this->assertDirectoryExists($custom, 'live module must be untouched');
		$this->assertSame('LIVE', trim(file_get_contents($custom.'/keep.txt')));
	}

	public function testRestoreOntoAbsentModuleStillWorks(): void
	{
		// Fresh restore (no current module dir) must simply materialise it.
		$id = 'zzdmmfresh';
		$custom = $this->customBase.'/'.$id;
		$this->assertDirectoryDoesNotExist($custom);

		$b = $this->makeBackup($id, array('marker.txt' => 'NEW'));
		$r = $b->restore();

		$this->assertTrue($r['success']);
		$this->assertSame('NEW', trim(file_get_contents($custom.'/marker.txt')));
	}

	public function testDeleteRefusesPathOutsideBackupRoot(): void
	{
		// A crafted/corrupted row must not drive a recursive delete outside the
		// backups root. Point backup_path at a temp dir OUTSIDE the root.
		$outside = sys_get_temp_dir().'/dmm_outside_'.getmypid();
		@mkdir($outside, 0755, true);
		file_put_contents($outside.'/precious.txt', 'keep me');

		$b = new DMMBackup(new FakeNoopDB());
		$b->id = 123456;
		$b->backup_path = $outside;
		$b->delete(null, true);

		$this->assertFileExists($outside.'/precious.txt', 'delete() must not touch out-of-root paths');
		$this->rmrf($outside);
	}

	public function testDeleteRemovesPathInsideBackupRoot(): void
	{
		$inside = $this->backupRoot.'/zzdmmdel_bak';
		@mkdir($inside, 0755, true);
		file_put_contents($inside.'/f.txt', 'x');

		$b = new DMMBackup(new FakeNoopDB());
		$b->id = 123457;
		$b->backup_path = $inside;
		$b->delete(null, true);

		$this->assertDirectoryDoesNotExist($inside, 'in-root backup dir must be deleted');
	}
}

/** Minimal DoliDB double: delete() issues a DELETE query we don't care about. */
class FakeNoopDB
{
	public function prefix()
	{
		return 'llx_';
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
	public function query($sql)
	{
		return true;
	}
	public function lasterror()
	{
		return '';
	}
}
