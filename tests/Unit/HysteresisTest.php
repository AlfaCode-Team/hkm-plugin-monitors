<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Hysteresis;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;
use Plugins\Monitors\Domain\Source;

final class HysteresisTest extends TestCase
{
    private function rule(
        Comparison $op = Comparison::Gt,
        float $fire = 100,
        ?float $resolve = null,
        int $fireAfter = 1,
        int $resolveAfter = 1,
    ): Rule {
        return Rule::define(
            id: 'r1', name: 'r', metric: 'http.errors', source: Source::Sum, comparison: $op,
            fireThreshold: $fire, resolveThreshold: $resolve, fireAfter: $fireAfter, resolveAfter: $resolveAfter,
        );
    }

    /**
     * Feed readings through the state machine.
     *
     * @param list<?float> $readings
     * @return array{fired: int, resolved: int, state: RuleState, kinds: list<string>}
     */
    private function feed(Rule $rule, array $readings): array
    {
        $state = new RuleState($rule->id);
        $kinds = [];

        foreach ($readings as $i => $value) {
            $step  = Hysteresis::step($rule, $state, $value, 1000 + $i);
            $state = $step->state;

            if ($step->transition !== null) {
                $kinds[] = $step->transition->kind->value;
            }
        }

        return [
            'fired'    => \count(array_keys($kinds, 'fired', true)),
            'resolved' => \count(array_keys($kinds, 'resolved', true)),
            'state'    => $state,
            'kinds'    => $kinds,
        ];
    }

    public function test_a_metric_oscillating_across_the_fire_threshold_fires_exactly_once_with_a_band(): void
    {
        // fire above 100, resolve only at or below 80. 90 is inside the band.
        $out = $this->feed($this->rule(fire: 100, resolve: 80), [110, 90, 110, 90, 110, 90, 110, 90, 110, 90]);

        $this->assertSame(1, $out['fired']);
        $this->assertSame(0, $out['resolved'], 'a reading inside the band must not resolve the alert');
        $this->assertTrue($out['state']->isFiring());
    }

    public function test_a_metric_oscillating_across_the_threshold_fires_exactly_once_with_streaks_alone(): void
    {
        // NO band (resolve == fire); only "3 in a row" to fire and to resolve.
        $rule = $this->rule(fire: 100, fireAfter: 3, resolveAfter: 3);

        // Three breaches in a row, then a metric that flaps across the line.
        $out = $this->feed($rule, [101, 101, 101, 99, 101, 99, 101, 99, 101, 99, 101]);

        $this->assertSame(1, $out['fired']);
        $this->assertSame(0, $out['resolved'], 'a good reading between breaches is not a recovery');
    }

    public function test_alternating_readings_never_fire_when_a_streak_is_required(): void
    {
        $out = $this->feed($this->rule(fire: 100, fireAfter: 2), [101, 99, 101, 99, 101, 99, 101, 99]);

        $this->assertSame(0, $out['fired']);
        $this->assertFalse($out['state']->isFiring());
    }

    public function test_the_same_oscillation_flaps_on_a_rule_with_no_hysteresis_which_is_why_it_matters(): void
    {
        $rule = $this->rule(fire: 100); // resolve == fire, streaks 1: undamped

        $this->assertFalse($rule->isDamped());

        $out = $this->feed($rule, [101, 99, 101, 99, 101, 99]);

        $this->assertSame(3, $out['fired']);
        $this->assertSame(3, $out['resolved']);
    }

    public function test_a_firing_rule_resolves_only_after_reaching_the_resolve_threshold(): void
    {
        $out = $this->feed($this->rule(fire: 100, resolve: 80), [110, 95, 85, 81]);
        $this->assertSame(['fired'], $out['kinds']);

        $out = $this->feed($this->rule(fire: 100, resolve: 80), [110, 95, 80]);
        $this->assertSame(['fired', 'resolved'], $out['kinds']);
    }

    public function test_resolving_needs_its_own_consecutive_clears_and_a_relapse_resets_the_count(): void
    {
        $rule = $this->rule(fire: 100, resolve: 80, resolveAfter: 3);

        // fire, clear, clear, RELAPSE into the band, clear, clear -> still firing.
        $out = $this->feed($rule, [110, 70, 70, 90, 70, 70]);
        $this->assertSame(['fired'], $out['kinds']);

        // ...and a third clear in a row now resolves.
        $out = $this->feed($rule, [110, 70, 70, 90, 70, 70, 70]);
        $this->assertSame(['fired', 'resolved'], $out['kinds']);
    }

    public function test_a_firing_rule_stays_quiet_however_long_it_keeps_breaching(): void
    {
        $out = $this->feed($this->rule(), array_fill(0, 50, 500.0));

        $this->assertSame(['fired'], $out['kinds']);
    }

    public function test_no_reading_neither_fires_nor_resolves_and_keeps_the_streaks(): void
    {
        $rule = $this->rule(fire: 100, resolve: 80, fireAfter: 3);

        // Two breaches, then silence, then the third breach: still counts as the third.
        $out = $this->feed($rule, [110, 110, null, null, 110]);
        $this->assertSame(['fired'], $out['kinds']);

        // A firing rule does not resolve because its metric went silent.
        $out = $this->feed($this->rule(fire: 100, resolve: 80), [110, null, null, null, null]);
        $this->assertSame(['fired'], $out['kinds']);
        $this->assertTrue($out['state']->isFiring());
        $this->assertSame(110.0, $out['state']->lastValue, 'the last real reading is kept');
    }

    public function test_less_than_rules_mirror_the_logic(): void
    {
        // Fire below 10, resolve at or above 20.
        $rule = $this->rule(Comparison::Lt, fire: 10, resolve: 20);

        $out = $this->feed($rule, [5, 15, 5, 15, 5]);
        $this->assertSame(['fired'], $out['kinds']);

        $out = $this->feed($rule, [5, 15, 20]);
        $this->assertSame(['fired', 'resolved'], $out['kinds']);
    }

    public function test_gt_and_gte_differ_exactly_at_the_threshold(): void
    {
        $this->assertSame([], $this->feed($this->rule(Comparison::Gt, 100), [100])['kinds']);
        $this->assertSame(['fired'], $this->feed($this->rule(Comparison::Gte, 100), [100])['kinds']);
        $this->assertSame([], $this->feed($this->rule(Comparison::Lt, 100), [100])['kinds']);
        $this->assertSame(['fired'], $this->feed($this->rule(Comparison::Lte, 100), [100])['kinds']);
    }

    public function test_the_transition_carries_the_threshold_that_was_crossed(): void
    {
        $rule = $this->rule(fire: 100, resolve: 80);

        $fired = Hysteresis::step($rule, new RuleState('r1'), 120, 5)->transition;
        $this->assertSame(AlertKind::Fired, $fired?->kind);
        $this->assertSame(100.0, $fired->threshold);

        $state    = Hysteresis::step($rule, new RuleState('r1'), 120, 5)->state;
        $resolved = Hysteresis::step($rule, $state, 50, 9)->transition;
        $this->assertSame(AlertKind::Resolved, $resolved?->kind);
        $this->assertSame(80.0, $resolved->threshold);
    }

    public function test_step_is_pure_and_does_not_touch_the_state_it_was_given(): void
    {
        $state = new RuleState('r1');
        Hysteresis::step($this->rule(), $state, 500, 1);

        $this->assertFalse($state->isFiring());
        $this->assertSame(0, $state->breachStreak);
    }
}
