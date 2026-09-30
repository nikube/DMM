<?php
/* Test stub for Dolibarr's core/modules/DolibarrModules.class.php.
 * Minimal base so a fake descriptor can be included by
 * DMMClient::uninstallModule() outside a Dolibarr bootstrap.
 */
if (!class_exists('DolibarrModules')) {
	class DolibarrModules
	{
		public $db;
		public $const_name;
		public $version;
		public $error = '';
		public $errors = array();
		public function __construct($db)
		{
			$this->db = $db;
		}
		public function init($options = '')
		{
			return 1;
		}
		public function remove($options = '')
		{
			return 1;
		}
	}
}
if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($key, $default = 0)
	{
		global $conf;
		return isset($conf->global->$key) ? (int) $conf->global->$key : $default;
	}
}
