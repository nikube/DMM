-- DMM migration 2.0.0 -> 2.1.0
-- Turn SQL migration on by default.
--
-- data.sql seeded auto_migrate to '0' while every read in the code defaulted to
-- '1', so a fresh install landed on the popup with no way to tell why. A module
-- that has just been installed needs its SQL applied and its menus/permissions
-- registered before it works at all, which makes that confirmation a question
-- with only one sensible answer.
--
-- This flips existing installs too. The settings table keeps no timestamp, so a
-- '0' that was seeded and a '0' the user chose are indistinguishable — anyone
-- who had deliberately turned it off will have to turn it off again. That is
-- the trade-off for fixing the default; the setting is still in the Advanced
-- tab and the change is announced in the changelog.
UPDATE llx_dmm_setting SET value = '1' WHERE name = 'auto_migrate' AND value = '0';

-- Drop "tracked but not installed" rows.
--
-- The registry is now what is installed on this Dolibarr, nothing else. Hubs,
-- token scans and the community index used to write a row per module they
-- advertised, so the table filled with entries describing modules that were
-- never here: on a test instance, 16 of 19 rows. Those rows drove a "Already
-- tracked" state in the catalogues that replaced the Install button, making
-- the module impossible to install from the very page offering it.
--
-- Discovery now lives in the "Add a module" tab, which reads hubs and tokens
-- live, so nothing is lost: a purged row reappears in the catalogue and can be
-- installed. Only rows with no files on disk go, and only when nothing ties
-- them to a deliberate user action:
--
--   * fk_dmm_token   -- attached to a credential on purpose
--   * dolistore_id   -- added by hand from a product URL
--   * subdir         -- a monorepo pointer someone configured
--
-- installed_version is checked as well as installed: a row that knows which
-- version is on disk is describing something real, whatever the flag says.
DELETE FROM llx_dmm_module
WHERE installed = 0
  AND (installed_version IS NULL OR installed_version = '')
  AND fk_dmm_token IS NULL
  AND dolistore_id IS NULL
  AND (subdir IS NULL OR subdir = '');
