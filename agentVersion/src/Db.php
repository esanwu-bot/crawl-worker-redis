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
        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']);

        try {
            $this->pdo = new PDO($serverDsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
            // 建库（库存在时静默）
            $this->pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $c['db']
            ));
            $this->pdo->exec(sprintf('USE `%s`', $c['db']));
        } catch (\PDOException $e) {
            throw new RuntimeException('MySQL 连接失败: ' . $e->getMessage());
        }
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

    /** 按关键词搜索已落库的产品系列 */
    public function searchTypes(string $keyword, int $limit = 20): array
    {
        $st = $this->pdo->prepare(
            'SELECT source, type_id, type_name, type_name_en
             FROM source_types
             WHERE type_name LIKE :kw OR type_name_en LIKE :kw
             ORDER BY type_id ASC LIMIT :lim'
        );
        $st->bindValue(':kw', '%' . $keyword . '%');
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** 拉取目录前 N 条（Agent 注入 LLM 用，候选来自真实 source_types） */
    public function listTypes(int $limit = 30): array
    {
        $st = $this->pdo->prepare(
            'SELECT source, type_id, type_name, type_name_en
             FROM source_types ORDER BY type_id ASC LIMIT :lim'
        );
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** 幂等 upsert 产品系列元数据 */
    public function upsertType(array $t): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare(
            'INSERT INTO source_types (source, type_id, type_name, type_name_en, created_at, updated_at)
             VALUES (:source, :tid, :cn, :en, :now, :now)
             ON DUPLICATE KEY UPDATE
               type_name = VALUES(type_name),
               type_name_en = VALUES(type_name_en),
               updated_at = VALUES(updated_at)'
        );
        $st->execute([
            ':source' => $t['source'],
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

    /** 按系列统计型号数 */
    public function modelsByType(): array
    {
        $st = $this->pdo->query(
            'SELECT type_id, type_name, COUNT(*) AS cnt FROM product_models GROUP BY type_id, type_name ORDER BY cnt DESC'
        );
        return $st->fetchAll();
    }

    /** 在已采型号库中搜索（Agent 校验/去重用） */
    public function searchModels(string $keyword, ?int $typeId = null, ?string $source = null, int $limit = 20): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($keyword !== '') {
            $where[]      = 'model LIKE :kw';
            $params[':kw'] = '%' . $keyword . '%';
        }
        if ($typeId !== null && $typeId > 0) {
            $where[]       = 'type_id = :tid';
            $params[':tid'] = $typeId;
        }
        if ($source !== null && $source !== '') {
            $where[]        = 'source = :src';
            $params[':src']  = $source;
        }
        $sql = 'SELECT source, model, remote_id, type_id, type_name, package, pdf, specs_json, source_url
                FROM product_models
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY id DESC LIMIT :lim';
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** 单条型号幂等 upsert */
    public function saveModel(array $row): void
    {
        $this->upsertModels([$row]);
    }
}
