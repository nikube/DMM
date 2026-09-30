<?php
/* Copyright (C) 2026 DMM Contributors */

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../lib/dolimodulemanager.lib.php';

final class ModulePermissionsTest extends TestCase
{
	private $root;

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir().'/dmm_permissions_'.uniqid('', true);
		mkdir($this->root.'/custom/example/nested', 0755, true);
		file_put_contents($this->root.'/custom/example/nested/module.php', '<?php');
	}

	protected function tearDown(): void
	{
		$file = $this->root.'/custom/example/nested/module.php';
		@chmod($file, 0644);
		@unlink($file);
		@rmdir($this->root.'/custom/example/nested');
		@rmdir($this->root.'/custom/example');
		@rmdir($this->root.'/custom');
		@rmdir($this->root);
	}

	public function testDolibarrReadOnlyFilesRemainReplaceable(): void
	{
		$file = $this->root.'/custom/example/nested/module.php';
		chmod($file, 0444);

		$this->assertNull(
			dmm_check_module_replace_permissions($this->root.'/custom/example'),
			'Dolibarr deploys module files as 0444; writable parent directories are sufficient to replace them.'
		);
	}

	public function testMissingTargetIsInstallableWhenCustomIsWritable(): void
	{
		$this->assertNull(dmm_check_module_replace_permissions($this->root.'/custom/newmodule'));
	}
}
