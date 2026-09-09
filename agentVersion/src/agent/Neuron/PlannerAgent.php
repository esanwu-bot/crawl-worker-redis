<?php
declare(strict_types=1);

namespace Cw\Agent\Neuron;

use Cw\Agent\AgentException;
use Cw\Db;
use Cw\Logger;
use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;

/**
 * Neuron v3 Agent Harness 上的规划 Agent（设计文档 §4.3 ②plan_step）。
 *
 * 按 Neuron 子类化模式只覆写三个钩子：
 *   - provider()      -> OpenAILike(SenseNova/任何 OpenAI 兼容网关)，可一行切换
 *   - instructions()  -> 规划约束（System Prompt）
 *   - tools()         -> Engine 只读能力工具（NeuronTools），自动进 function calling
 *
 * agent loop / tool round-trip / history 均由 Harness 托管，这里只写业务。
 */
class PlannerAgent extends Agent
{
    /** 记录本实例在 agent loop 中真实执行过的工具调用（审计/元数据用）。 */
    public array $toolRunLog = [];

    public function __construct(
        private Db $db,
        private Logger $log,
        private array $llmCfg,
    ) {
        parent::__construct();
    }

    /** @return Tool[] 当前 agent 可用的 Engine 工具 */
    protected function tools(): array
    {
        return NeuronTools::forPlanner($this->db, function (string $name, array $inputs): void {
            $this->toolRunLog[] = ['name' => $name, 'inputs' => $inputs];
        });
    }

    protected function instructions(): string
    {
        return <<<'INSTRUCTIONS'
你是电子元器件采集系统的规划 Agent。用户会用中文描述想采集的系列。
你可以调用只读工具 list_series 检索/核对站点真实目录（type_id 与名称的权威来源）。
你绝不能编造 type_id，也不能修改目录中 id 的含义。

执行流程：
1) 先调用一次 list_series，用用户意图中最具区分度的关键词核对目录候选；
2) 结合「用户消息中给出的目录」与「工具返回结果」，选出匹配用户意图的 type_id；
3) 最终回复必须是单个合法 JSON 对象（不要 Markdown 代码块、不要额外解释）。

输出结构（严格遵守）：
{"type_ids":[整数数组],"reason":"选择理由中文简述","target_pages":整数}

约束：
- type_ids 只能取真实目录中出现的 id；
- 用户范围过大/未命中任何系列时，type_ids 给空数组并在 reason 说明；
- target_pages 参考用户的页数描述，没提就用较小值（如 3）。
INSTRUCTIONS;
    }

    protected function provider(): AIProviderInterface
    {
        $base = rtrim((string)($this->llmCfg['base_url'] ?? ''), '/');
        $key  = (string)($this->llmCfg['api_key'] ?? '');
        if ($base === '' || $key === '') {
            throw new AgentException('未配置 agent.llm.base_url / agent.llm.api_key，无法构建 Neuron PlannerAgent');
        }

        // 与本仓库原有 curl 直连保持一致：phpstudy 本机无系统 CA 时跳过校验；
        // 生产环境请通过 llm.verify_ssl / CA bundle 打开校验。
        $http = new GuzzleHttpClient(
            timeout: (float)($this->llmCfg['timeout'] ?? 30),
            options: ['verify' => false],
        );

        return new OpenAILike(
            baseUri: $base,
            key: $key,
            model: (string)($this->llmCfg['model'] ?? 'gpt-4o-mini'),
            parameters: ['temperature' => 0.0],
            strict_response: false,
            httpClient: $http,
        );
    }

    public function getToolRunLog(): array
    {
        return $this->toolRunLog;
    }
}
