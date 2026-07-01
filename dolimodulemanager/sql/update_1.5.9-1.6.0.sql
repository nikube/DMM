-- DMM migration 1.5.9 -> 1.6.0
-- Adds channel/source/branch tracking to llx_dmm_module for dev channel and
-- multi-source discovery (token, hub, dolibarr-community).
-- Portable syntax only (works on MySQL, MariaDB and PostgreSQL). No MariaDB-only
-- "IF NOT EXISTS": Dolibarr run_sql() tolerates DB_ERROR_COLUMN_ALREADY_EXISTS /
-- DB_ERROR_RECORD_ALREADY_EXISTS, so these statements are safe to replay.
ALTER TABLE llx_dmm_module ADD COLUMN channel VARCHAR(20) DEFAULT 'stable';
ALTER TABLE llx_dmm_module ADD COLUMN source VARCHAR(30) DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD COLUMN branch VARCHAR(100) DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD COLUMN branch_dev VARCHAR(100) DEFAULT NULL;

-- Default settings for the new developer mode and Dolibarr community YAML import.
-- Plain INSERT (not MySQL-only "INSERT IGNORE"); duplicate rows raise a tolerated error.
INSERT INTO llx_dmm_setting (name, value) VALUES ('dev_mode_enabled', '0');
INSERT INTO llx_dmm_setting (name, value) VALUES ('community_yaml_url', 'https://raw.githubusercontent.com/Dolibarr/dolibarr-community-modules/main/index.yaml');
INSERT INTO llx_dmm_setting (name, value) VALUES ('community_yaml_enabled', '0');
