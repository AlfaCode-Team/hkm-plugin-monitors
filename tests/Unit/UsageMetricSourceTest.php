<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Source;
use Plugins\Monitors\Infrastructure\Gateways\UsageMetricSource;
use Plugins\Usage\Domain\Growth;
use Tests\Plugins\Monitors\Support\StubUsage;

final class UsageMetricSourceTest extends TestCase
{
    private function rule(Source $source, int $window = 7200, ?string $tenant = null): Rule
    {
        return Rule::define(
            id: 'r', name: 'r', metric: 'http.errors', source: $source, comparison: Comparison::Gt,
            fireThreshold: 1, windowSeconds: $window, tenant: $tenant,
        );
    }

    public function test_sum_reads_the_window_ending_now_for_the_rules_tenant(): void
    {
        $usage = new StubUsage();
        $usage->sum = 42;

        $value = (new UsageMetricSource($usage))->read($this->rule(Source::Sum, 7200, 't-1'), 100_000);

        $this->assertSame(42.0, $value);
        $this->assertSame(['sum', 'http.errors', 100_000 - 7200, 100_001, 't-1'], $usage->calls[0]);
    }

    public function test_gauge_reads_the_latest_level_and_null_means_no_data(): void
    {
        $usage = new StubUsage();
        $source = new UsageMetricSource($usage);

        $this->assertNull($source->read($this->rule(Source::Gauge), 1));

        $usage->latest = 7.5;
        $this->assertSame(7.5, $source->read($this->rule(Source::Gauge), 1));
        $this->assertSame(['latest', 'http.errors', null], $usage->calls[1]);
    }

    public function test_growth_reads_the_ratio_and_an_empty_previous_window_is_no_data_not_infinity(): void
    {
        $usage = new StubUsage();
        $source = new UsageMetricSource($usage);

        $usage->growth = new Growth(30, 10);
        $this->assertSame(2.0, $source->read($this->rule(Source::Growth, 3600), 1));
        $this->assertSame(['growth', 'http.errors', 3600, null], $usage->calls[0]);

        $usage->growth = new Growth(30, 0);
        $this->assertNull($source->read($this->rule(Source::Growth, 3600), 1));
    }
}
