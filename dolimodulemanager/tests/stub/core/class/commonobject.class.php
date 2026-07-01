<?php
/* Minimal test stub for Dolibarr's CommonObject.
 *
 * DMM CRUD classes extend CommonObject but the restore/backup tests only use
 * plain properties + custom methods, so an empty base class is enough. Guarded
 * so a real Dolibarr include still wins.
 */

if (!class_exists('CommonObject')) {
	class CommonObject
	{
		public $db;
		public $error = '';
		public $errors = array();
	}
}
