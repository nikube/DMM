-- DoliModuleManager - Default settings
-- Copyright (C) 2026 DMM Contributors

INSERT INTO llx_dmm_setting (name, value) VALUES ('check_interval', '86400');
INSERT INTO llx_dmm_setting (name, value) VALUES ('backup_retention_days', '30');
INSERT INTO llx_dmm_setting (name, value) VALUES ('backup_retention_count', '5');
INSERT INTO llx_dmm_setting (name, value) VALUES ('notify_email', '');
INSERT INTO llx_dmm_setting (name, value) VALUES ('temp_dir', '');
INSERT INTO llx_dmm_setting (name, value) VALUES ('auto_check', '1');
INSERT INTO llx_dmm_setting (name, value) VALUES ('auto_migrate', '0');
INSERT INTO llx_dmm_setting (name, value) VALUES ('hub_urls', '[{"url":"https://raw.githubusercontent.com/nikube/DMMHub/master/dmmhub.json","enabled":1}]');
INSERT INTO llx_dmm_setting (name, value) VALUES ('dev_mode_enabled', '0');
INSERT INTO llx_dmm_setting (name, value) VALUES ('community_yaml_url', 'https://raw.githubusercontent.com/Dolibarr/dolibarr-community-modules/main/index.yaml');
INSERT INTO llx_dmm_setting (name, value) VALUES ('community_yaml_enabled', '1');
