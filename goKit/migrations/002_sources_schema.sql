-- =============================================================
-- Migration 002: 配置式数据源 / Entity / Schema
--
-- 对应 后台方案.md 的 Phase 2：让「新增数据源」从写代码变成配置对象。
--   sources          Source Definition（连接 + 端点 + 采集单元）
--   source_entities  数据源的采集对象类型
--   source_schemas   实体级 Schema（版本化，可发布）
--   source_fields    Schema 的字段映射
--
-- 所有语句必须幂等：迁移在每次启动时按文件名顺序重复执行。
-- =============================================================

CREATE TABLE IF NOT EXISTS `sources` (
  `id`          VARCHAR(64)  NOT NULL COMMENT '数据源标识（= task.source）',
  `name`        VARCHAR(120) NOT NULL DEFAULT '' COMMENT '展示名',
  `adapter`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT 'connector/adapter 类型：shikues/maccms/...',
  `entity`      VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '默认采集对象类型',
  `site`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '站点根地址',
  `api_base`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '接口地址',
  `page_size`   INT          NOT NULL DEFAULT 0 COMMENT '每页条数',
  `detail_url`  VARCHAR(500) NOT NULL DEFAULT '' COMMENT '详情地址模板',
  `status`      VARCHAR(16)  NOT NULL DEFAULT 'enabled' COMMENT 'enabled/disabled',
  `config_json` JSON         NULL COMMENT '完整 Source Definition（含 units）',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Source Definition（配置式数据源）';

CREATE TABLE IF NOT EXISTS `source_entities` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`     VARCHAR(64)  NOT NULL,
  `entity`     VARCHAR(32)  NOT NULL,
  `name`       VARCHAR(120) NOT NULL DEFAULT '',
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'enabled' COMMENT 'enabled/disabled',
  `created_at` DATETIME     NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_source_entity` (`source`, `entity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据源的采集对象类型（Source → Entity）';

CREATE TABLE IF NOT EXISTS `source_schemas` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`          VARCHAR(64) NOT NULL,
  `entity`          VARCHAR(32) NOT NULL,
  `version`         INT         NOT NULL DEFAULT 1,
  `status`          VARCHAR(16) NOT NULL DEFAULT 'draft' COMMENT 'draft/published',
  `mapping_json`    JSON        NULL COMMENT '字段映射 [{field,source_path,type,required,...}]',
  `pagination_json` JSON        NULL COMMENT '分页定位 {items,page,page_count,total}',
  `created_at`      DATETIME    NOT NULL,
  `updated_at`      DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schema_version` (`source`, `entity`, `version`),
  KEY `idx_source_entity` (`source`, `entity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实体级 Schema（版本化，可发布）';

CREATE TABLE IF NOT EXISTS `source_fields` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schema_id`    BIGINT UNSIGNED NOT NULL,
  `field`        VARCHAR(120) NOT NULL,
  `source_path`  VARCHAR(255) NOT NULL DEFAULT '',
  `type`         VARCHAR(32)  NOT NULL DEFAULT 'string' COMMENT 'string/integer/number/boolean/text/json',
  `required`     TINYINT(1)   NOT NULL DEFAULT 0,
  `remark`       VARCHAR(255) NOT NULL DEFAULT '',
  `sort`         INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_schema_field` (`schema_id`, `field`),
  KEY `idx_schema` (`schema_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Schema 字段映射（外部字段 → Canonical 字段）';
