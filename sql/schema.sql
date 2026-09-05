-- =============================================================
-- 结果层 Schema（MySQL 5.7+）
-- 任务/游标在 Redis Stream 与 Hash 中，业务结果全部落到 MySQL
-- 由 bin/init_db.php 自动执行；也支持手工导入
-- =============================================================

CREATE TABLE IF NOT EXISTS `source_types` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`      VARCHAR(32)  NOT NULL COMMENT '数据源标识，如 shikues',
  `type_id`     INT          NOT NULL COMMENT '站点产品系列 id',
  `type_name`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '系列中文名',
  `type_name_en`VARCHAR(120) NOT NULL DEFAULT '' COMMENT '系列英文名',
  `extra`       JSON         NULL COMMENT '原始元信息(可选)',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_type` (`source`, `type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品系列/分类元数据';

CREATE TABLE IF NOT EXISTS `product_models` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`      VARCHAR(32)  NOT NULL COMMENT '数据源标识，如 shikues',
  `model`       VARCHAR(120) NOT NULL COMMENT '型号/TYPE 主标识',
  `remote_id`   INT          NOT NULL DEFAULT 0 COMMENT '源站记录 id',
  `type_id`     INT          NOT NULL DEFAULT 0 COMMENT '所属系列 id',
  `type_name`   VARCHAR(120) NOT NULL DEFAULT '' COMMENT '所属系列名(快照)',
  `package`     VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '封装(如 SMAF)',
  `pdf`         VARCHAR(200) NOT NULL DEFAULT '' COMMENT '数据手册标识',
  `specs_json`  JSON         NULL COMMENT '参数明细，a..t 与站点一一对应',
  `source_url`  VARCHAR(300) NOT NULL DEFAULT '',
  `crawled_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_model` (`source`, `model`),
  KEY `idx_type_id` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='型号结果表（source+model 幂等 upsert）';
