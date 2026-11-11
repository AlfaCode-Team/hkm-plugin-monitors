<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Source;

final class RuleTest extends TestCase
{
    /** @param array<string, mixed> $o */
    private function define(array $o = []): Rule
    {
        return Rule::define(...array_merge([
            'id' => 'r1', 'name' => 'api.errors', 'metric' => 'http.errors', 'source' => Source::Sum,
            'comparison' => Comparison::Gt, 'fireThreshold' => 100.0,
        ], $o));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'gt resolve above fire'  => [['resolveThreshold' => 120.0]],
            'lt resolve below fire'  => [['comparison' => Comparison::Lt, 'resolveThreshold' => 50.0]],
            'infinite threshold'     => [['fireThreshold' => INF]],
            'zero streak'            => [['fireAfter' => 0]],
            'huge streak'            => [['resolveAfter' => 5000]],
            'window not whole hours' => [['windowSeconds' => 5400]],
            'window too small'       => [['windowSeconds' => 60]],
            'bad name'               => [['name' => 'Has Spaces']],
            'bad metric'             => [['metric' => "x'; DROP"]],
            'bad email'              => [['mailTo' => ['not-an-email']]],
            'header injection'       => [['mailTo' => ["a@b.com\r\nBcc: evil@x.com"]]],
            'no channel at all'      => [['webhook' => false, 'console' => false]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalid')]
    public function test_nonsense_is_refused_at_definition_time(array $override): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->define($override);
    }

    public function test_resolve_defaults_to_fire_and_that_rule_is_reported_as_undamped(): void
    {
        $rule = $this->define();

        $this->assertSame(100.0, $rule->resolveThreshold);
        $this->assertFalse($rule->isDamped());
    }

    public function test_either_a_band_or_a_streak_counts_as_damping(): void
    {
        $this->assertTrue($this->define(['resolveThreshold' => 80.0])->isDamped());
        $this->assertTrue($this->define(['fireAfter' => 2])->isDamped());
        $this->assertTrue($this->define(['resolveAfter' => 2])->isDamped());
    }

    public function test_a_gauge_has_no_window(): void
    {
        $this->assertSame(0, $this->define(['source' => Source::Gauge, 'windowSeconds' => 7200])->windowSeconds);
    }

    public function test_recipients_are_lower_cased_and_deduplicated(): void
    {
        $rule = $this->define(['mailTo' => ['Ops@Example.com', 'ops@example.com ', 'dev@example.com']]);

        $this->assertSame(['ops@example.com', 'dev@example.com'], $rule->mailTo);
        $this->assertSame(['mail', 'webhook', 'console'], $rule->channels());
    }

    public function test_a_rule_with_only_mail_is_valid(): void
    {
        $rule = $this->define(['mailTo' => ['a@b.co'], 'webhook' => false, 'console' => false]);

        $this->assertSame(['mail'], $rule->channels());
    }
}
