-- DoliModuleManager - GitHub token storage
-- Copyright (C) 2026 DMM Contributors

CREATE TABLE llx_dmm_token(
	rowid           INTEGER AUTO_INCREMENT PRIMARY KEY,
	label           VARCHAR(255) NOT NULL,
	token           TEXT NOT NULL,
	github_owner    VARCHAR(255) DEFAULT NULL,
	token_type      VARCHAR(20) DEFAULT 'pat',
	status          TINYINT DEFAULT 1,
	last_validated  DATETIME DEFAULT NULL,
	note            TEXT DEFAULT NULL,
	date_creation   DATETIME NOT NULL,
	tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat   INTEGER NOT NULL,
	fk_user_modif   INTEGER DEFAULT NULL
) ENGINE=innodb;
