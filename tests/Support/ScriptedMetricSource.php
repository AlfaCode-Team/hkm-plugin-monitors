<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use Plugins\Monitors\Application\Ports\MetricSource;
use Plugins\Monitors\Domain\Rule;

/** A metric source whose readings the test dictates, by rule name. */
final class ScriptedMetricSource implements MetricSource
{
    /** @var array<string, ?float> */
    private array $values = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var (callable(Rule): void)|null */
    public $onRead = null;

    public function set(string $ruleName, ?float $value): void
    {
        $this->values[$ruleName] = $value;
        unset($this->failures[$ruleName]);
    }

    public function failFor(string $ruleName): void
    {
        $this->failures[$ruleName] = new \RuntimeException('usage store is unreachable');
    }

    public function read(Rule $rule, int $now): ?float
    {
        if ($this->onRead !== null) {
            ($this->onRead)($rule);
        }

        if (isset($this->failures[$rule->name])) {
            throw $this->failures[$rule->name];
        }

        return $this->values[$rule->name] ?? null;
    }
}
