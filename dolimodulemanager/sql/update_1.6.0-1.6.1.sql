-- DMM migration 1.6.0 -> 1.6.1
-- Flip community YAML discovery on by default. Users who deliberately turned it
-- off on 1.6.0 will see it re-enabled; that's acceptable — it's a public read-only
-- source and can be disabled again in Settings.
UPDATE llx_dmm_setting SET value = '1' WHERE name = 'community_yaml_enabled' AND value = '0';
