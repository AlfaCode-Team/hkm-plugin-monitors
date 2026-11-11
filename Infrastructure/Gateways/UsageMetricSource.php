<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Gateways;

use Plugins\Monitors\Application\Ports\MetricSource;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Source;
use Plugins\Usage\API\Contracts\UsageContract;

/**
 * Reads metrics through the Usage plugin's PUBLISHED contract and nothing else.
 *
 * The windows are anchored to the hour (usage keeps hourly buckets), and `sum`
 * reads the rows whose bucket starts in [now - window, now], so the partial
 * current hour counts. A rule is therefore "the last N whole-or-partial hours",
 * not "the last N x 3600 seconds to the second".
 */
final class UsageMetricSource implements MetricSource
{
    public function __construct(private readonly UsageContract $usage) {}

    public function read(Rule $rule, int $now): ?float
    {
        return match ($rule->source) {
            Source::Sum => (float) $this->usage->sum(
                $rule->metric,
                (new \DateTimeImmutable('@' . ($now - $rule->windowSeconds))),
                (new \DateTimeImmutable('@' . ($now + 1))),
                $rule->tenant,
            ),
            Source::Gauge  => $this->usage->latest($rule->metric, $rule->tenant),
            Source::Growth => $this->usage->growth($rule->metric, $rule->windowSeconds, $rule->tenant)->ratio(),
        };
    }
}
