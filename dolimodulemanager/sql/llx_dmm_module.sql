-- DoliModuleManager - Module registry and cache
-- Copyright (C) 2026 DMM Contributors

CREATE TABLE llx_dmm_module(
	rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
	module_id               VARCHAR(128) NOT NULL,
	name                    VARCHAR(255) DEFAULT NULL,
	description             TEXT DEFAULT NULL,
	author                  VARCHAR(255) DEFAULT NULL,
	license                 VARCHAR(50) DEFAULT NULL,
	url                     VARCHAR(500) DEFAULT NULL,
	github_repo             VARCHAR(255) NOT NULL,
	fk_dmm_token            INTEGER NOT NULL,
	installed_version       VARCHAR(20) DEFAULT NULL,
	installed               TINYINT DEFAULT 0,
	cache_latest_version    VARCHAR(20) DEFAULT NULL,
	cache_latest_compatible VARCHAR(20) DEFAULT NULL,
	cache_changelog         TEXT DEFAULT NULL,
	cache_manifest_json     TEXT DEFAULT NULL,
	cache_etag              VARCHAR(128) DEFAULT NULL,
	cache_last_check        DATETIME DEFAULT NULL,
	cache_last_error        VARCHAR(500) DEFAULT NULL,
	date_creation           DATETIME NOT NULL,
	tms                     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
