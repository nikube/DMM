-- DoliModuleManager - Indexes for llx_dmm_setting

ALTER TABLE llx_dmm_setting ADD UNIQUE INDEX uk_dmm_setting_name (name);
