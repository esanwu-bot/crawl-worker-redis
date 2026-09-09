<?php
declare(strict_types=1);

namespace Cw\Adapter;

use Cw\Contract\SourceAdapter;
use Cw\Db;
use Cw\Http;
use RuntimeException;

/**
 * MacCMS Adapter（影视域：Category → VOD）。
 *
 * 走 MacCMS 官方标准化 JSON 接口（flag.md §9 V1 依据）：
 *   GET {base}/api.php/provide/vod/?ac=list&pg={page}&pagesize={n}[&t={class}]
 * 不抓 HTML。Connector=Http、Extractor=官方 list 结构、Normalizer=vod_* 列映射，
 * 全部收敛在本 Adapter 内部，Runtime 只看到 Canonical Record。
 */
class MacCmsAdapter implements SourceAdapter
{
    private Http $http;
    /** @var array{source:string,site:string,api_base:string,entity:string,page_size:int,units:array,detail_url:string} */
    private array $c;

    public function __construct(Http $http, array $cfg)
    {
        $this->http = $http;
        $this->c = [
            'source'     => (string)($cfg['source'] ?? 'maccms'),
            'site'       => rtrim((string)($cfg['site'] ?? ''), '/'),
            'api_base'   => rtrim((string)($cfg['api_base'] ?? ''), '/'),
            'entity'     => (string)($cfg['entity'] ?? 'vod'),
            'page_size'  => (int)($cfg['page_size'] ?? 20),
            'units'      => (array)($cfg['units'] ?? []),
            'detail_url' => (string)($cfg['detail_url'] ?? '/index.php/vod/detail/id/{id}.html'),
        ];
        if ($this->c['api_base'] === '') {
            throw new RuntimeException('maccms 数据源缺少 api_base 配置');
        }
    }

    public function source(): string
    {
        return $this->c['source'];
    }

    /** 类目目录来自配置（MacCMS 类目是页面接口不提供 JSON 目录；配置即 Source Definition） */
    public function discoverUnits(Db $db): array
    {
        $units = $this->c['units'];
        if (!$units) {
            $units = [['unit_id' => 0, 'unit_name' => '全部影片']];
        }
        $out = [];
        foreach ($units as $u) {
            $unitId = (string)($u['unit_id'] ?? '');
            $name   = trim((string)($u['unit_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $db->upsertType([
                'source'  => $this->c['source'],
                'type_id' => (int)$unitId,
                'cn_name' => $name,
                'en_name' => '',
            ]);
            $out[] = [
                'entity'    => $this->c['entity'],
                'unit_id'   => $unitId,
                'unit_name' => $name,
                'params'    => (array)($u['params'] ?? []),
            ];
        }
        return $out;
    }

    public function executeList(array $task): array
    {
        $cur      = $task['cursor'];
        $page     = (int)$cur['page'];
        $unitId   = (string)$cur['unit_id'];
        $unitName = (string)($cur['unit_name'] ?? '');
        $params   = (array)($task['params'] ?? []);
        $limit    = (int)(($params['limit'] ?? 0) ?: $this->c['page_size']);

        $q = ['ac' => 'list', 'pg' => $page, 'pagesize' => $limit];
        if ($unitId !== '' && (int)$unitId > 0) {
            $q['t'] = (int)$unitId; // 官方分类参数
        }
        if (!empty($params['h'])) {
            $q['h'] = (int)$params['h']; // 近 N 小时增量更新
        }

        $json = $this->http->getJson($this->c['api_base'], $q);
        if ((int)($json['code'] ?? 0) !== 1) {
            throw new RuntimeException('MacCMS 接口异常 code=' . ($json['code'] ?? '?')
                . ' msg=' . (string)($json['msg'] ?? ''));
        }

        $items    = is_array($json['list'] ?? null) ? $json['list'] : [];
        $lastPage = max(1, (int)($json['pagecount'] ?? 1));

        // 相对 vod_pic 补全主机
        $site = $this->c['site'];

        $records = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $ext = (string)($it['vod_id'] ?? '');
            if ($ext === '') {
                continue;
            }
            $pic = trim((string)($it['vod_pic'] ?? ''));
            if ($pic !== '' && !str_starts_with($pic, 'http')) {
                $pic = $site . '/' . ltrim($pic, '/');
            }

            // 领域可检索字段白名单（raw 保留完整原始行）
            $payload = [];
            foreach (['type_id','type_name','vod_name','vod_sub','vod_remarks','vod_year',
                      'vod_area','vod_lang','vod_actor','vod_director','vod_score','vod_time',
                      'vod_hits','vod_play_url'] as $k) {
                if (isset($it[$k]) && trim((string)$it[$k]) !== '') {
                    $payload[$k] = (string)$it[$k];
                }
            }

            $records[] = [
                'source'       => $this->c['source'],
                'entity'       => $this->c['entity'],
                'unit_id'      => $unitId,
                'unit_name'    => $unitName,
                'external_id'  => $ext,
                'title'        => trim((string)($it['vod_name'] ?? $ext)),
                'url'          => $site . str_replace('{id}', $ext, $this->c['detail_url']),
                'image'        => $pic,
                'published_at' => '',
                'updated_at'   => '',
                'payload'      => $payload,
                'raw'          => $it,
                'source_url'   => $this->sourceUrl($q),
                'page'         => $page,
            ];
        }

        return [
            'page'      => $page,
            'last_page' => $lastPage,
            'ended'     => $page >= $lastPage || !$items,
            'records'   => $records,
        ];
    }

    private function sourceUrl(array $q): string
    {
        return $this->c['api_base'] . '?' . http_build_query($q);
    }
}
