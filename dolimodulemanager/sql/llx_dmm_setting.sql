-- DoliModuleManager - Settings key-value store
-- Copyright (C) 2026 DMM Contributors

CREATE TABLE llx_dmm_setting(
	rowid   INTEGER AUTO_INCREMENT PRIMARY KEY,
	name    VARCHAR(128) NOT NULL,
	value   TEXT DEFAULT NULL
) ENGINE=innodb;
