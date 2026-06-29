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
