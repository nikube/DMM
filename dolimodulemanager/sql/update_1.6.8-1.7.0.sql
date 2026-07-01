-- DMM migration 1.6.8 -> 1.7.0
-- Adds DoliStore as a module source: marketplace page can register modules
-- coming from www.dolistore.com (free modules with direct-download).
-- Portable syntax only (no MariaDB-only IF NOT EXISTS / IF EXISTS): run_sql()
-- tolerates "column/key already exists" and "no foreign key to drop" on replay,
-- and Dolibarr's pgsql converter rewrites ADD INDEX / DROP FOREIGN KEY for PostgreSQL.
ALTER TABLE llx_dmm_module ADD COLUMN dolistore_id INTEGER DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD INDEX idx_dmm_module_dolistore (dolistore_id);

-- Make backup FK nullable + ON DELETE SET NULL so backups can outlive their
-- module row (registry renames, manual cleanups). Order matters: drop the FK
-- before MODIFY, then re-add it ON DELETE SET NULL.
ALTER TABLE llx_dmm_backup DROP FOREIGN KEY fk_dmm_backup_module;
ALTER TABLE llx_dmm_backup MODIFY COLUMN fk_dmm_module INTEGER DEFAULT NULL;
ALTER TABLE llx_dmm_backup ADD CONSTRAINT fk_dmm_backup_module FOREIGN KEY (fk_dmm_module) REFERENCES llx_dmm_module(rowid) ON DELETE SET NULL;
