<?php

declare(strict_types=1);

namespace Plugins\Monitors\Application\Ports;

use Plugins\Monitors\Domain\Rule;

interface MetricSource
{
    /**
     * The value the rule is about, or null when there is none to judge (a gauge
     * never reported; growth from an empty window).
     *
     * @throws \Throwable when the metric cannot be read — the evaluator records
     *                    it against that rule and moves on
     */
    public function read(Rule $rule, int $now): ?float;
}
