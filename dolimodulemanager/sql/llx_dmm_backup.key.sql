-- DoliModuleManager - Indexes for llx_dmm_backup

ALTER TABLE llx_dmm_backup ADD INDEX idx_dmm_backup_module (fk_dmm_module);
ALTER TABLE llx_dmm_backup ADD CONSTRAINT fk_dmm_backup_module FOREIGN KEY (fk_dmm_module) REFERENCES llx_dmm_module(rowid) ON DELETE SET NULL;
