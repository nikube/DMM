-- DoliModuleManager - Backup history
-- Copyright (C) 2026 DMM Contributors

CREATE TABLE llx_dmm_backup(
	rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_dmm_module   INTEGER NOT NULL,
	module_id       VARCHAR(128) NOT NULL,
	version_from    VARCHAR(20) NOT NULL,
	version_to      VARCHAR(20) NOT NULL,
	backup_path     VARCHAR(500) NOT NULL,
	backup_size     BIGINT DEFAULT NULL,
	status          VARCHAR(20) DEFAULT 'ok',
	date_creation   DATETIME NOT NULL
) ENGINE=innodb;
