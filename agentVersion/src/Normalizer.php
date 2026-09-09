<?php
declare(strict_types=1);

namespace Cw;

/**
 * 把站点行数据清洗为落库模型：
 *   - model      = a（站点语义固定为 TYPE/型号列）
 *   - specs_json = b..t 参数明细，按站点原 key 保留，天然支持不同系列字段异构
 *   - package    = i 列为非数值时视为封装名（多数系列封装在该列，兼容通用场景）
 */
class Normalizer
{
    /** @return list<array> */
    public static function toModelRows(array $items, string $source, int $typeId, string $typeName, string $sourceUrl): array
    {
        $rows = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $model = trim((string)($it['a'] ?? ''));
            if ($model === '') {
                continue;
            }

            $specs = [];
            foreach (['b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t'] as $k) {
                if (isset($it[$k]) && $it[$k] !== null && trim((string)$it[$k]) !== '') {
                    $specs[$k] = (string)$it[$k];
                }
            }

            // 不同系列“封装”所在字母列不固定（type1 在 i、type7 在 j…），
            // 用形态识别（如 SMAF / SOT-23 / DO-214AC）扫描参数列兜底
            $package = '';
            foreach ($specs as $v) {
                if (self::looksLikePackage($v)) {
                    $package = $v;
                    break;
                }
            }

            $rows[] = [
                'source'    => $source,
                'model'     => $model,
                'remote_id' => (int)($it['id'] ?? 0),
                'type_id'   => $typeId,
                'type_name' => $typeName,
                'package'   => $package,
                'pdf'       => (string)($it['pdf'] ?? ''),
                'specs'     => $specs,
                'source_url'=> $sourceUrl,
            ];
        }
        return $rows;
    }

    /**
     * 封装名形态启发式：
     *  - 全字母缩写：SMAF / SMBF / MSOD ...
     *  - 常见前缀+数字：SOT-23 / SOD-123 / TO-220 / DO-214AC / DFN1006 ...
     * 排除了纯数值与“数值+单位”（如 50 / 40V / 1.5A）等普通参数。
     */
    private static function looksLikePackage(string $v): bool
    {
        $v = trim($v);
        if ($v === '' || is_numeric($v)) {
            return false;
        }
        if (preg_match('/^[A-Za-z]{2,}[A-Za-z0-9]{0,10}$/', $v)) {
            return true; // SMAF / AEC 等纯字母开头串
        }
        return (bool)preg_match('/^(TO|SOD|SOT|SMA|SMB|SMC|DO|DFN|QFN|LL|MSOP|TSSOP|TSOT|SC|USON|X2)[-]?[0-9A-Za-z]{1,10}$/', $v);
    }
}
