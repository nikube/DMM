<?php
/* Test stub for Dolibarr's core/lib/files.lib.php.
 *
 * DMMClient require_once's this file at load time. The portability tests only
 * exercise the constructor + tableExists(), which touch none of these helpers,
 * so empty no-op shims are enough to let the class load outside a full
 * Dolibarr bootstrap. Guarded so a real Dolibarr include still wins.
 */

if (!function_exists('dol_mkdir')) {
	function dol_mkdir($dir, $dataroot = '', $newmask = '')
	{
		return 0;
	}
}
if (!function_exists('dol_delete_dir_recursive')) {
	function dol_delete_dir_recursive($dir)
	{
		return 0;
	}
}
if (!function_exists('dolCopyDir')) {
	function dolCopyDir($srcfile, $destfile, $newmask, $overwriteifexists)
	{
		return 0;
	}
}
if (!function_exists('dol_uncompress')) {
	function dol_uncompress($inputfile, $outputdir)
	{
		return array();
	}
}
