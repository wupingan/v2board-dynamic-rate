CREATE TABLE IF NOT EXISTS `v2_dynamic_rate_rule` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `server_type` VARCHAR(32) NOT NULL COMMENT 'vmess/trojan/shadowsocks/vless/tuic/hysteria/anytls/v2node',
  `server_id` INT(11) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `base_rate` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai',
  `rules_json` TEXT NULL COMMENT 'JSON array of time ranges',
  `last_applied_rate` DECIMAL(10,2) NULL,
  `updated_at` INT(11) NOT NULL,
  `created_at` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_server` (`server_type`,`server_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
