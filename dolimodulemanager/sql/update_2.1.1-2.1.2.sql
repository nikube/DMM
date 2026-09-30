-- DMM migration 2.1.1 -> 2.1.2
-- Remember the exact release tag resolved by the update check so the install
-- downloads that ref instead of guessing "v<version>" (a release tagged 1.2.3
-- without the v prefix could never be installed).
-- Portable syntax: Dolibarr run_sql() tolerates DB_ERROR_COLUMN_ALREADY_EXISTS.
ALTER TABLE llx_dmm_module ADD COLUMN cache_download_tag VARCHAR(100) DEFAULT NULL;
