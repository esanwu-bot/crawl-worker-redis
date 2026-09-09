<?php
declare(strict_types=1);

namespace Cw;

use RuntimeException;

/**
 * shikues 站点公开数据接口客户端。
 * 说明：www.shikues.com 产品目录页由 categories.js 异步加载以下接口，
 * 我们以同一数据源做“列表页-详情行”的采集建模。
 */
class ApiClient
{
    private Http $http;
    private string $base;
    private int $type;

    public function __construct(Http $http, string $base, int $type)
    {
        $this->http = $http;
        $this->base = $base;
        $this->type = $type;
    }

    /** GET /productType —— 产品系列列表（网站左侧“产品类型”栏数据源） */
    public function productTypes(): array
    {
        $res = $this->http->getJson($this->base . '/productType', ['type' => $this->type, 'a' => '']);
        $data = $this->pick($res, 'data');
        if (!is_array($data)) {
            throw new RuntimeException('productType 响应缺少 data');
        }
        return $data;
    }

    /** GET /productList —— 某系列某一页的型号行数据（网站表格数据源） */
    public function productList(int $productTypeId, int $page, int $limit): array
    {
        $res = $this->http->getJson($this->base . '/productList', [
            'product_type_id' => $productTypeId,
            'page'            => $page,
            'limit'           => $limit,
        ]);
        // 站点在部分 UA/网络情况下会把 JSON 再包一层字符串
        if (is_string($res)) {
            $res = json_decode($res, true);
        }
        $data = $this->pick($res, 'data');
        if (!is_array($data)) {
            throw new RuntimeException('productList 响应缺少 data');
        }
        return $res;
    }

    private function pick(array $res, string $key)
    {
        return $res[$key] ?? null;
    }
}
