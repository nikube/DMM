-- DMM migration: add use_for_public column to tokens
ALTER TABLE llx_dmm_token ADD COLUMN use_for_public TINYINT DEFAULT 0;
