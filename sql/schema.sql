-- =============================================================
-- 结果层 Schema（MySQL 5.7+）
-- 任务/游标在 Redis Stream 与 Hash 中，业务结果全部落到 MySQL
-- 由 bin/init_db.php 自动执行；也支持手工导入
-- =============================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='[遗留] 型号结果表（早期 shikues 特化版本，新采集统一走 crawl_records）';

-- ---------------------------------------------------------
-- 通用层：Source Type + Crawl Job + Canonical Record（flag.md §5/§8/§11）
-- 两套数据源(Shikues/MacCMS)共用同一套表，source 字段只差一个值
-- ---------------------------------------------------------

CREATE TABLE IF NOT EXISTS `source_types` (
  `source`     VARCHAR(32)  NOT NULL COMMENT '数据源标识：shikues / maccms ...',
  `type_id`    INT          NOT NULL COMMENT '采集单元 id（系列 / 分类 等，由 Adapter 定义）',
  `cn_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `en_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`source`, `type_id`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='采集单元目录（跨数据源通用，来源 Adapter::discoverUnits）';

CREATE TABLE IF NOT EXISTS `crawl_jobs` (
  `id`          VARCHAR(64)  NOT NULL COMMENT '作业 id（seed 生成）',
  `source`      VARCHAR(32)  NOT NULL COMMENT '数据源标识：shikues / maccms ...',
  `status`      VARCHAR(20)  NOT NULL DEFAULT 'seeding' COMMENT 'seeding/active/done/dead',
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
