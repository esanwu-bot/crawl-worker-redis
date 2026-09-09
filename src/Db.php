<?php
declare(strict_types=1);

namespace Cw;

use PDO;
use RuntimeException;

/**
 * MySQL 结果层：
 *  - 首次运行自动建库建表（幂等）
 *  - product_models 以 (source, model) 唯一键幂等 upsert
 */
class Db
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->connect();
        $this->ensureSchema();
    }

    private function connect(): void
    {
        $c = $this->cfg;
        // 支持 MySQL 5.7 / 8.0 双实例并存的环境（phpstudy 套件）：默认端口若拒绝，
        // 依次尝试 alt_ports 并兜底 3306 / 3307 / 3308，让运维不用为脚本改配置。
        $ports = [(int)$c['port']];
        foreach ((array)($c['alt_ports'] ?? []) as $p) {
            $ports[] = (int)$p;
        }
        $ports = array_values(array_unique($ports));
        foreach ([3306, 3307, 3308] as $p) {
            if (!in_array($p, $ports, true)) {
                $ports[] = $p;
            }
        }
        $lastErr = null;
        foreach ($ports as $p) {
            $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $p, $c['charset']);
            try {
                $this->pdo = new PDO($serverDsn, $c['user'], $c['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 10,
                ]);
                // 记录实际连通端口，便于后续 CW_MYSQL_PORT 写回配置
                $this->cfg['port']  = $p;
                $this->cfg['db']    = $c['db'];
                // 建库（库存在时静默）
                $this->pdo->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    $c['db']
                ));
                $this->pdo->exec(sprintf('USE `%s`', $c['db']));
                return;
            } catch (\PDOException $e) {
                $lastErr = $e;
                continue;
            }
        }
        throw new RuntimeException('MySQL 连接失败 (尝试 ' . implode('/', $ports) . '): ' . ($lastErr ? $lastErr->getMessage() : 'unknown'));
    }

    private function ensureSchema(): void
    {
        $sqlFile = dirname(__DIR__) . '/sql/schema.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('缺少 schema 文件: ' . $sqlFile);
        }
        $sql = file_get_contents($sqlFile);
        // 去掉整行注释后按分号拆成可执行语句
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*/', trim($sql));
        foreach ($parts as $stmt) {
            if (trim($stmt) !== '') {
                $this->pdo->exec($stmt);
            }
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** 幂等 upsert 产品系列元数据 */
    public function upsertType(array $t): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            'INSERT INTO source_types (source, type_id, cn_name, en_name, updated_at)
             VALUES (:source, :tid, :cn, :en, :now)
             ON DUPLICATE KEY UPDATE
               cn_name = VALUES(cn_name),
               en_name = VALUES(en_name),
               updated_at = VALUES(updated_at)'
        );
        $st->execute([
            ':source' => (string)$t['source'],
            ':tid'    => (int)$t['type_id'],
            ':cn'     => (string)($t['cn_name'] ?? ''),
            ':en'     => (string)($t['en_name'] ?? ''),
            ':now'    => $now,
        ]);
    }

    /** 批量幂等 upsert 型号结果 */
    public function upsertModels(array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $now  = date('Y-m-d H:i:s');
        $sql  = 'INSERT INTO product_models
                 (source, model, remote_id, type_id, type_name, package, pdf, specs_json, source_url, crawled_at, updated_at)
                 VALUES (:source, :model, :remote_id, :type_id, :type_name, :package, :pdf, :specs, :url, :now, :now)
                 ON DUPLICATE KEY UPDATE
                   remote_id  = VALUES(remote_id),
                   type_id    = VALUES(type_id),
                   type_name  = VALUES(type_name),
                   package    = VALUES(package),
                   pdf        = VALUES(pdf),
                   specs_json = VALUES(specs_json),
                   source_url = VALUES(source_url),
                   updated_at = VALUES(updated_at)';
        $st = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($rows as $r) {
                $st->execute([
                    ':source'    => $r['source'],
                    ':model'     => $r['model'],
                    ':remote_id' => $r['remote_id'],
                    ':type_id'   => $r['type_id'],
                    ':type_name' => $r['type_name'],
                    ':package'   => $r['package'] ?? '',
                    ':pdf'       => $r['pdf'] ?? '',
                    ':specs'     => json_encode($r['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':url'       => $r['source_url'] ?? '',
                    ':now'       => $now,
                ]);
                $n++;
            }
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('型号批量落库失败: ' . $e->getMessage());
        }
    }

    public function countModels(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM product_models')->fetchColumn();
    }

    public function countTypes(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM source_types')->fetchColumn();
    }

    /** 最近落库样本（验证用） */
    public function recentModels(int $limit = 8): array
    {
        $st = $this->pdo->prepare(
            'SELECT source, model, remote_id, type_id, type_name, package, pdf, specs_json
             FROM product_models ORDER BY id DESC LIMIT :lim'
        );
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** 按系列统计型号数（遗留 product_models 专用） */
    public function modelsByType(): array
    {
        $st = $this->pdo->query(
            'SELECT type_id, type_name, COUNT(*) AS cnt FROM product_models GROUP BY type_id, type_name ORDER BY cnt DESC'
        );
        return $st->fetchAll();
    }

    // ================= 通用层：Crawl Job =================

    /**
     * 创建通用采集作业。scope 为播种单元快照（跨数据源统一，source 字段即数据源差异）。
     *
     * @param list<array{entity:string,unit_id:int|string,unit_name:string}> $scope
     */
    public function createCrawlJob(string $source, array $scope, ?string $id = null): string
    {
        $jobId = $id ?: 'job-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $now   = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            'INSERT INTO crawl_jobs (id, source, status, units_total, units_done, units_dead, records, scope_json, created_at, updated_at)
             VALUES (:id, :source, :status, :total, 0, 0, 0, :scope, :now, :now)'
        );
        $st->execute([
            ':id'     => $jobId,
            ':source' => $source,
            ':status' => count($scope) > 0 ? 'active' : 'done',
            ':total'  => count($scope),
            ':scope'  => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':now'    => $now,
        ]);
        return $jobId;
    }

    public function jobInfo(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM crawl_jobs WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** 最近作业列表 */
    public function jobs(?string $source = null, int $limit = 20): array
    {
        $sql = 'SELECT * FROM crawl_jobs';
        $params = [];
        if ($source !== null && $source !== '') {
            $sql .= ' WHERE source = :src';
            $params[':src'] = $source;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, $limit);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Worker 在一个采集单元收尾时回写作业状态（unit 完成/死信，均会推进 job 状态机）。
     */
    public function unitFinished(string $jobId, bool $done, int $records = 0): void
    {
        if ($jobId === '') {
            return;
        }
        $col = $done ? 'units_done' : 'units_dead';
        $this->pdo->prepare(
            "UPDATE crawl_jobs SET {$col} = {$col} + 1, records = records + :r, updated_at = NOW() WHERE id = :id"
        )->execute([':r' => $records, ':id' => $jobId]);

        // 全部单元收尾后收敛终态：有死信归 dead，否则 done
        $this->pdo->prepare(
            'UPDATE crawl_jobs
               SET status = CASE WHEN units_dead > 0 THEN :dead ELSE :done END, updated_at = NOW()
             WHERE id = :id AND units_done + units_dead >= units_total'
        )->execute([':dead' => 'dead', ':done' => 'done', ':id' => $jobId]);
    }

    public function countJobs(?string $source = null): int
    {
        if ($source !== null && $source !== '') {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM crawl_jobs WHERE source = :src');
            $st->execute([':src' => $source]);
            return (int)$st->fetchColumn();
        }
        return (int)$this->pdo->query('SELECT COUNT(*) FROM crawl_jobs')->fetchColumn();
    }

    // ================= 通用层：Canonical Record =================

    /**
     * 批量幂等 upsert Canonical Record（flag.md §5 三件套）：
     * 唯一键 (source, entity, unit_id, external_id)，重复采集不产生新行。
     */
    public function upsertRecords(array $records): int
    {
        $records = array_values(array_filter($records, static fn($r) => is_array($r) && ($r['external_id'] ?? '') !== ''));
        if (!$records) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO crawl_records
                 (job_id, source, entity, unit_id, unit_name, external_id, title, url, image,
                  source_url, page, payload_json, raw_json, crawled_at, updated_at)
                 VALUES
                 (:job_id, :source, :entity, :unit_id, :unit_name, :external_id, :title, :url, :image,
                  :source_url, :page, :payload, :raw, :now, :now)
                 ON DUPLICATE KEY UPDATE
                   job_id     = VALUES(job_id),
                   unit_name  = VALUES(unit_name),
                   title      = VALUES(title),
                   url        = VALUES(url),
                   image      = VALUES(image),
                   source_url = VALUES(source_url),
                   page       = VALUES(page),
                   payload_json = VALUES(payload_json),
                   raw_json     = VALUES(raw_json),
                   updated_at   = VALUES(updated_at)';
        $st = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            $n = 0;
            foreach ($records as $r) {
                $st->execute([
                    ':job_id'     => (string)($r['job_id'] ?? ''),
                    ':source'     => (string)$r['source'],
                    ':entity'     => (string)($r['entity'] ?? ''),
                    ':unit_id'    => (string)($r['unit_id'] ?? ''),
                    ':unit_name'  => (string)($r['unit_name'] ?? ''),
                    ':external_id'=> (string)$r['external_id'],
                    ':title'      => (string)($r['title'] ?? ''),
                    ':url'        => (string)($r['url'] ?? ''),
                    ':image'      => (string)($r['image'] ?? ''),
                    ':source_url' => (string)($r['source_url'] ?? ''),
                    ':page'       => (int)($r['page'] ?? 0),
                    ':payload'    => json_encode($r['payload'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':raw'        => json_encode($r['raw'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':now'        => $now,
                ]);
                $n++;
            }
            $this->pdo->commit();
            return $n;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('通用记录落库失败: ' . $e->getMessage());
        }
    }

    public function countRecords(?string $source = null): int
    {
        if ($source !== null && $source !== '') {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM crawl_records WHERE source = :src');
            $st->execute([':src' => $source]);
            return (int)$st->fetchColumn();
        }
        return (int)$this->pdo->query('SELECT COUNT(*) FROM crawl_records')->fetchColumn();
    }

    /** 按 source+entity 聚合记录数（跨数据源一览） */
    public function recordsByEntity(): array
    {
        $st = $this->pdo->query(
            'SELECT source, entity, COUNT(*) AS cnt FROM crawl_records GROUP BY source, entity ORDER BY cnt DESC'
        );
        return $st->fetchAll();
    }

    /** 最近落库的通用记录（验证/调试用） */
    public function recentRecords(int $limit = 8, ?string $source = null): array
    {
        $sql = 'SELECT job_id, source, entity, unit_id, unit_name, external_id, title, url, image, payload_json, raw_json
                FROM crawl_records';
        $params = [];
        if ($source !== null && $source !== '') {
            $sql .= ' WHERE source = :src';
            $params[':src'] = $source;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
