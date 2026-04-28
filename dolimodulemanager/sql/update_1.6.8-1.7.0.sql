-- DMM migration 1.6.8 -> 1.7.0
-- Adds DoliStore as a module source: marketplace page can register modules
-- coming from www.dolistore.com (free modules with direct-download).
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS dolistore_id INTEGER DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD INDEX IF NOT EXISTS idx_dmm_module_dolistore (dolistore_id);
