-- DMM migration 1.5.9 -> 1.6.0
-- Adds channel/source/branch tracking to llx_dmm_module for dev channel and
-- multi-source discovery (token, hub, dolibarr-community).
-- Idempotent: re-run safe. Dolibarr's run_sql() ignores per-statement errors when
-- usesavepoint is set, but we additionally guard with IF NOT EXISTS / INSERT IGNORE.
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS channel VARCHAR(20) DEFAULT 'stable';
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS source VARCHAR(30) DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS branch VARCHAR(100) DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS branch_dev VARCHAR(100) DEFAULT NULL;

-- Default settings for the new developer mode and Dolibarr community YAML import.
INSERT IGNORE INTO llx_dmm_setting (name, value) VALUES ('dev_mode_enabled', '0');
INSERT IGNORE INTO llx_dmm_setting (name, value) VALUES ('community_yaml_url', 'https://raw.githubusercontent.com/Dolibarr/dolibarr-community-modules/main/index.yaml');
INSERT IGNORE INTO llx_dmm_setting (name, value) VALUES ('community_yaml_enabled', '0');
