-- DoliModuleManager - Indexes for llx_dmm_module

ALTER TABLE llx_dmm_module ADD UNIQUE INDEX uk_dmm_module (module_id, github_repo);
ALTER TABLE llx_dmm_module ADD INDEX idx_dmm_module_token (fk_dmm_token);
ALTER TABLE llx_dmm_module ADD CONSTRAINT fk_dmm_module_token FOREIGN KEY (fk_dmm_token) REFERENCES llx_dmm_token(rowid);
