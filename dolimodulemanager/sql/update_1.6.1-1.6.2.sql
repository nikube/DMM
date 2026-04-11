-- DMM migration 1.6.1 -> 1.6.2
-- Adds git host abstraction and monorepo subdirectory support so community modules
-- hosted on GitLab (inligit.fr) and those living in subdirectories of the Dolibarr
-- community monorepo can be installed.
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS git_host VARCHAR(20) DEFAULT 'github';
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS git_base_url VARCHAR(200) DEFAULT NULL;
ALTER TABLE llx_dmm_module ADD COLUMN IF NOT EXISTS subdir VARCHAR(200) DEFAULT NULL;

-- Backfill git_host for existing rows. Safe to re-run.
UPDATE llx_dmm_module SET git_host = 'github' WHERE git_host IS NULL OR git_host = '';
