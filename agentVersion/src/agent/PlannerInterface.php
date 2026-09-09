<?php
declare(strict_types=1);

namespace Cw\Agent;

interface PlannerInterface
{
    /**
     * 把自然语言意图转成可执行计划。
     */
    public function plan(string $intent): Plan;
}
