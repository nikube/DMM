-- DoliModuleManager - Backup history
-- Copyright (C) 2026 DMM Contributors

CREATE TABLE llx_dmm_backup(
	rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
	-- nullable: a backup may outlive the module row (e.g. after a registry
	-- rename or a manual cleanup). The FK uses ON DELETE SET NULL so backup
	-- artifacts on disk can still be inspected/restored from the rescue script.
	fk_dmm_module   INTEGER DEFAULT NULL,
	module_id       VARCHAR(128) NOT NULL,
	version_from    VARCHAR(20) NOT NULL,
	version_to      VARCHAR(20) NOT NULL,
	backup_path     VARCHAR(500) NOT NULL,
	backup_size     BIGINT DEFAULT NULL,
	status          VARCHAR(20) DEFAULT 'ok',
	date_creation   DATETIME NOT NULL
) ENGINE=innodb;
