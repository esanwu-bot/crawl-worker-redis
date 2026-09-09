<?php
declare(strict_types=1);

namespace Cw\Adapter;

use Cw\ApiClient;
use Cw\Contract\SourceAdapter;
use Cw\Db;
use Cw\Normalizer;
use RuntimeException;

/**
 * Shikues Adapter（电子元器件域：Series → Model）。
 *
 * 把原 ApiClient（连接协议）+ Normalizer（行清洗）收敛为“一个数据源适配器”，
 * 对外只产出 Canonical Record。Runtime 无需感知 product_type_id / a..t 字段等任何细节。
 */
class ShikuesAdapter implements SourceAdapter
{
    private ApiClient $api;
    private string $apiBase;
    private string $source;
    private string $entity;

    public function __construct(ApiClient $api, string $apiBase, string $source = 'shikues', string $entity = 'model')
    {
        $this->api     = $api;
        $this->apiBase = rtrim($apiBase, '/');
        $this->source  = $source;
        $this->entity  = $entity;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** 发现“产品系列”，并把目录元数据沉淀到通用目录表 source_types */
    public function discoverUnits(Db $db): array
    {
        $types = $this->api->productTypes();
        if (!is_array($types)) {
            throw new RuntimeException('shikues productType 响应异常');
        }
        $units = [];
        foreach ($types as $t) {
            if (!is_array($t)) {
                continue;
            }
            $id = (string)($t['id'] ?? '');
            $cn = trim((string)($t['cn_name'] ?? $t['name'] ?? ''));
            $en = trim((string)($t['en_name'] ?? ''));
            if ($id === '' || $cn === '') {
                continue;
            }
            $db->upsertType([
                'source'  => $this->source,
                'type_id' => (int)$id,
                'cn_name' => $cn,
                'en_name' => $en,
            ]);
            $units[] = [
                'entity'    => $this->entity,
                'unit_id'   => $id,
                'unit_name' => $cn,
            ];
        }
        return $units;
    }

    public function executeList(array $task): array
    {
        $cur      = $task['cursor'];
        $page     = (int)$cur['page'];
        $unitId   = (int)$cur['unit_id'];
        $unitName = (string)($cur['unit_name'] ?? '');
        $limit    = (int)(($task['params']['limit'] ?? 0) ?: 15);
        if ($unitId <= 0) {
            throw new RuntimeException('shikues 任务缺少有效 unit_id');
        }

        $resp = $this->api->productList($unitId, $page, $limit);
        $items    = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $lastPage = max(1, (int)($resp['last_page'] ?? 1));

        $sourceUrl = $this->apiBase . '/productList?product_type_id='
            . $unitId . '&page=' . $page . '&limit=' . $limit;

        $rows = Normalizer::toModelRows($items, $this->source, $unitId, $unitName, $sourceUrl);

        $records = [];
        foreach ($rows as $r) {
            // 去重键：优先源站行 id；缺省行以 model 兜底（同一 unit 内型号唯一）
            $externalId = (int)$r['remote_id'] > 0
                ? (string)$r['remote_id']
                : 'm:' . $r['model'];
            $records[] = [
                'source'       => $this->source,
                'entity'       => $this->entity,
                'unit_id'      => (string)$unitId,
                'unit_name'    => $unitName,
                'external_id'  => $externalId,
                'title'        => $r['model'],
                'url'          => '',
                'image'        => '',
                'published_at' => '',
                'updated_at'   => '',
                'payload'      => [
                    'model'   => $r['model'],
                    'package' => $r['package'],
                    'pdf'     => $r['pdf'],
                    'specs'   => $r['specs'],
                ],
                'raw'        => $r['raw'] ?? [],
                'source_url' => $sourceUrl,
                'page'       => $page,
            ];
        }

        return [
            'page'      => $page,
            'last_page' => $lastPage,
            'ended'     => $page >= $lastPage,
            'records'   => $records,
        ];
    }
}
