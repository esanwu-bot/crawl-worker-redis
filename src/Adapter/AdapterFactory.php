<?php
declare(strict_types=1);

namespace Cw\Adapter;

use Cw\ApiClient;
use Cw\Http;
use RuntimeException;

/**
 * Adapter 装配工厂：根据 config.sources（Source Definition）构建注册表。
 * 新增数据源时只需在 config.sources 增加一个配置段并在本工厂注册分派逻辑。
 */
final class AdapterFactory
{
    /**
     * @param array $cfg         完整 config（需含 http 与 sources 段）
     * @param bool  $shareHttp   true 时各数据源共享一个 Http 实例（生产省连接）
     * @return AdapterRegistry
     */
    public static function fromConfig(array $cfg, bool $shareHttp = true): AdapterRegistry
    {
        $http = $shareHttp ? new Http($cfg['http'] ?? []) : null;
        $sources = (array)($cfg['sources'] ?? []);
        if (!$sources) {
            throw new RuntimeException('config.sources 为空，未配置任何数据源');
        }

        $out = [];
        foreach ($sources as $name => $sc) {
            if (!is_array($sc)) {
                continue;
            }
            $kind = (string)($sc['adapter'] ?? $name);
            $ownHttp = $shareHttp ? $http : new Http($cfg['http'] ?? []);

            switch ($kind) {
                case 'shikues':
                    $api = new ApiClient($ownHttp, (string)($sc['api_base'] ?? ''), (int)($sc['type'] ?? 1));
                    $out[$name] = new ShikuesAdapter(
                        $api,
                        (string)($sc['api_base'] ?? ''),
                        (string)$name,
                        (string)($sc['entity'] ?? 'model')
                    );
                    break;

                case 'maccms':
                    $sc['source'] = (string)$name;
                    $out[$name] = new MacCmsAdapter($ownHttp, $sc);
                    break;

                default:
                    throw new RuntimeException('未知 adapter 类型: ' . $kind . '（source=' . $name . '）');
            }
        }
        return new AdapterRegistry($out);
    }
}
