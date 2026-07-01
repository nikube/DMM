<?php
/* Test stub for Dolibarr's core/lib/files.lib.php.
 *
 * DMMClient / DMMBackup require_once this file at load time. These shims are
 * REAL filesystem implementations (not no-ops) so tests can exercise the
 * install/restore file operations against actual temp directories outside a
 * full Dolibarr bootstrap. Guarded so a real Dolibarr include still wins.
 */

if (!function_exists('dol_mkdir')) {
	function dol_mkdir($dir, $dataroot = '', $newmask = '')
	{
		if (is_dir($dir)) {
			return 0;
		}
		return @mkdir($dir, 0755, true) ? 1 : -1;
	}
}
if (!function_exists('dol_delete_dir_recursive')) {
	function dol_delete_dir_recursive($dir)
	{
		if (!is_dir($dir)) {
			return is_file($dir) ? (@unlink($dir) ? 1 : 0) : 0;
		}
		$items = scandir($dir);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir.'/'.$item;
			if (is_dir($path) && !is_link($path)) {
				dol_delete_dir_recursive($path);
			} else {
				@unlink($path);
			}
		}
		return @rmdir($dir) ? 1 : 0;
	}
}
if (!function_exists('dolCopyDir')) {
	function dolCopyDir($srcfile, $destfile, $newmask, $overwriteifexists)
	{
		if (!is_dir($srcfile)) {
			return -1;
		}
		if (!is_dir($destfile) && !@mkdir($destfile, 0755, true)) {
			return -1;
		}
		$items = scandir($srcfile);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$src = $srcfile.'/'.$item;
			$dst = $destfile.'/'.$item;
			if (is_dir($src)) {
				if (dolCopyDir($src, $dst, $newmask, $overwriteifexists) < 0) {
					return -1;
				}
			} else {
				if (!$overwriteifexists && is_file($dst)) {
					continue;
				}
				if (!@copy($src, $dst)) {
					return -1;
				}
			}
		}
		return 1;
	}
}
if (!function_exists('dol_uncompress')) {
	function dol_uncompress($inputfile, $outputdir)
	{
		return array();
	}
}
if (!function_exists('dol_syslog')) {
	function dol_syslog($message, $level = 7)
	{
		return null;
	}
}
