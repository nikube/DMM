-- DoliModuleManager - Indexes for llx_dmm_module

ALTER TABLE llx_dmm_module ADD UNIQUE INDEX uk_dmm_module (module_id, github_repo);
ALTER TABLE llx_dmm_module ADD INDEX idx_dmm_module_token (fk_dmm_token);
-- FK removed: fk_dmm_token can be NULL for public repos
