<?php
declare(strict_types=1);

namespace Cw;

use RuntimeException;

/**
 * 代理层错误（失败归因 A 类：代理本身不可用/认证失败）。
 *
 * 与"目标站/业务错误"（普通 RuntimeException）区分开：
 *  - A 类由 Http::getJson 内部消化 —— 换下一个代理重试，不进入任务层的 attempt/死信；
 *  - B/C 类仍抛 RuntimeException，交由 Worker 既有"重试 → 死信"语义处理。
 */
class ProxyException extends RuntimeException
{
}
