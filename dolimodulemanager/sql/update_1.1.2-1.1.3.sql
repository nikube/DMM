-- DMM migration: allow public repos (fk_dmm_token nullable)
ALTER TABLE llx_dmm_module MODIFY fk_dmm_token INTEGER DEFAULT NULL;
ALTER TABLE llx_dmm_module DROP FOREIGN KEY fk_dmm_module_token;
