<?php
/* Bootstrap for DMM unit tests.
 *
 * Lets class/DMMClient.class.php load outside a full Dolibarr install by
 * defining DOL_DOCUMENT_ROOT to a local stub tree (which provides a no-op
 * core/lib/files.lib.php). When run inside the real dolitest harness,
 * DOL_DOCUMENT_ROOT is already defined and this is a no-op.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', __DIR__.'/stub');
}

// DMMBackup writes/reads under DOL_DATA_ROOT/dolimodulemanager/backups and
// DOL_DOCUMENT_ROOT/custom. Point DOL_DATA_ROOT at a throwaway temp tree so the
// restore/backup tests operate on real (but isolated) directories.
if (!defined('DOL_DATA_ROOT')) {
	define('DOL_DATA_ROOT', sys_get_temp_dir().'/dmm_test_dataroot');
}
if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 4);
}

// DMM classes lazily dol_include_once() the module lib; map it to the repo path.
if (!function_exists('dol_include_once')) {
	function dol_include_once($relpath)
	{
		$path = __DIR__.'/..'.preg_replace('#^/dolimodulemanager#', '', $relpath);
		if (is_file($path)) {
			require_once $path;
		}
	}
}

// PHPUnit autoloader if present (dolitest's vendor or a local one).
foreach (array(
	__DIR__.'/../vendor/autoload.php',
	__DIR__.'/../../../dolitest/vendor/autoload.php',
) as $autoload) {
	if (is_file($autoload)) {
		require_once $autoload;
		break;
	}
}
