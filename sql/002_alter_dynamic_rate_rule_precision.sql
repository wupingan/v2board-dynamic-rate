ALTER TABLE `v2_dynamic_rate_rule`
  MODIFY COLUMN `base_rate` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  MODIFY COLUMN `last_applied_rate` DECIMAL(10,2) NULL;
