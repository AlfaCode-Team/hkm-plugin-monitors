<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Gateways;

use Plugins\Monitors\Application\Ports\MetricSource;
use Plugins\Monitors\Domain\Rule;

/**
 * The metric source the CLI is wired with. Metrics are published by the Usage
 * plugin as a request-scoped contract, which only exists inside a container the
 * kernel built for a module graph — a worker's, or an HTTP request's. A command
 * built at boot has neither, so it must not pretend to read metrics: a wrong
 * zero here would resolve a real alert.
 */
final class WorkerOnlyMetricSource implements MetricSource
{
    public function read(Rule $rule, int $now): ?float
    {
        throw new \LogicException('Metrics are read inside the worker. Queue an evaluation with `monitors:evaluate`.');
    }
}
