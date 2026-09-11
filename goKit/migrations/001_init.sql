-- =============================================================
-- Crawler Engine (Go) 结果层 Schema
-- 与 PHP 版 crawl-worker-redis 的表结构保持兼容（同样的表名/列名/唯一键），
-- 因此 Go 版可以直接复用 PHP 已经跑出来的 cw 数据库，反之亦然。
--
-- 任务/游标在 Redis Stream 与 Hash 中；业务结果全部落到 MySQL。
-- 由 crawler-cli initdb / 各命令启动时自动执行（幂等）。
-- =============================================================

CREATE TABLE IF NOT EXISTS `source_types` (
  `source`     VARCHAR(32)  NOT NULL COMMENT '数据源标识：shikues / maccms ...',
  `type_id`    INT          NOT NULL COMMENT '采集单元 id（系列 / 分类 等，由 Adapter 定义）',
  `cn_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `en_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`source`, `type_id`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采集单元目录（跨数据源通用，来源 Adapter::Discover）';

CREATE TABLE IF NOT EXISTS `crawl_jobs` (
  `id`          VARCHAR(64)  NOT NULL COMMENT '作业 id（seed 生成）',
  `source`      VARCHAR(32)  NOT NULL COMMENT '数据源标识：shikues / maccms ...',
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'seeding' COMMENT 'seeding/active/done/dead/cancelled/paused',
  `units_total` INT          NOT NULL DEFAULT 0 COMMENT '计划采集单元数',
  `units_done`  INT          NOT NULL DEFAULT 0 COMMENT '已完成单元数',
  `units_dead`  INT          NOT NULL DEFAULT 0 COMMENT '失败转入死信单元数',
  `records`     INT          NOT NULL DEFAULT 0 COMMENT '累计落库 Canonical Record 数',
  `scope_json`  JSON         NULL COMMENT '播种单元快照 [{entity,unit_id,unit_name}]',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用采集作业（跨数据源统一 Job-Task 模型）';

CREATE TABLE IF NOT EXISTS `crawl_records` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '最近写入的作业 id',
  `source`       VARCHAR(32)  NOT NULL COMMENT '数据源标识',
  `entity`       VARCHAR(32)  NOT NULL COMMENT '采集对象类型：model / vod ...',
  `unit_id`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '所属采集单元 id（系列/分类）',
  `unit_name`    VARCHAR(120) NOT NULL DEFAULT '' COMMENT '所属采集单元名(快照)',
  `external_id`  VARCHAR(190) NOT NULL COMMENT '源站对象 id（本库去重键之一）',
  `title`        VARCHAR(300) NOT NULL DEFAULT '' COMMENT 'Canonical 标题',
  `url`          VARCHAR(500) NOT NULL DEFAULT '',
  `image`        VARCHAR(500) NOT NULL DEFAULT '',
  `source_url`   VARCHAR(500) NOT NULL DEFAULT '' COMMENT '本条记录来源页/接口 URL',
  `page`         INT          NOT NULL DEFAULT 0 COMMENT '采集页码',
  `payload_json` JSON         NULL COMMENT '领域可检索字段（按 schema 检索用）',
  `raw_json`     JSON         NULL COMMENT '原始记录全量备份',
  `crawled_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_record` (`source`, `entity`, `unit_id`, `external_id`),
  KEY `idx_source` (`source`),
  KEY `idx_source_entity` (`source`, `entity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用 Canonical Record 表（三件套：canonical+payload+raw）';

-- ---------------------------------------------------------
-- 编排/审计层（Go 版新增，PHP 版可忽略）
-- 用于 HTTP API 与后续 Crawler Agent 的行为审计。
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `crawl_agent_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`      VARCHAR(64)  NOT NULL DEFAULT '',
  `actor`       VARCHAR(32)  NOT NULL DEFAULT 'system' COMMENT 'system / api / agent',
  `tool`        VARCHAR(64)  NOT NULL COMMENT '调用的能力/动作名',
  `params_json` JSON         NULL,
  `result_json` JSON         NULL COMMENT '结果摘要（不入全量数据）',
  `risk`        VARCHAR(16)  NOT NULL DEFAULT 'read' COMMENT 'read / write',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Engine/Agent 行为审计';
