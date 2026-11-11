<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/**
 * The state machine. A pure function: (rule, state, reading, time) -> state
 * and, at most, one transition.
 *
 *   ok      + breach (value on the bad side of FIRE)        -> breachStreak++ ;
 *                                                              fires at fireAfter
 *   ok      + anything else                                  -> breachStreak = 0
 *   firing  + clear  (value NOT on the bad side of RESOLVE)  -> clearStreak++ ;
 *                                                              resolves at resolveAfter
 *   firing  + anything else (incl. the band between them)    -> clearStreak = 0
 *   no reading (null)                                        -> nothing moves
 *
 * "No reading" is neither a breach nor a recovery. A metric that stopped
 * reporting is a different problem from one that crossed a line, and letting it
 * resolve an alert would turn a dead pipeline into a green dashboard.
 */
final class Hysteresis
{
    public static function step(Rule $rule, RuleState $state, ?float $value, int $now): Evaluation
    {
        if ($value === null) {
            return new Evaluation(self::with($state, $state->status, $state->breachStreak, $state->clearStreak, null, $now, $state->changedAt), null);
        }

        if (!$state->isFiring()) {
            if (!$rule->comparison->breaches($value, $rule->fireThreshold)) {
                return new Evaluation(self::with($state, RuleState::OK, 0, 0, $value, $now, $state->changedAt), null);
            }

            $streak = $state->breachStreak + 1;

            if ($streak >= $rule->fireAfter) {
                return new Evaluation(
                    self::with($state, RuleState::FIRING, 0, 0, $value, $now, $now),
                    new Transition(AlertKind::Fired, $value, $rule->fireThreshold, $now),
                );
            }

            return new Evaluation(self::with($state, RuleState::OK, $streak, 0, $value, $now, $state->changedAt), null);
        }

        // Firing. "Clear" is judged against the RESOLVE threshold.
        if ($rule->comparison->breaches($value, $rule->resolveThreshold)) {
            return new Evaluation(self::with($state, RuleState::FIRING, 0, 0, $value, $now, $state->changedAt), null);
        }

        $streak = $state->clearStreak + 1;

        if ($streak >= $rule->resolveAfter) {
            return new Evaluation(
                self::with($state, RuleState::OK, 0, 0, $value, $now, $now),
                new Transition(AlertKind::Resolved, $value, $rule->resolveThreshold, $now),
            );
        }

        return new Evaluation(self::with($state, RuleState::FIRING, 0, $streak, $value, $now, $state->changedAt), null);
    }

    private static function with(RuleState $s, string $status, int $breach, int $clear, ?float $value, int $now, int $changedAt): RuleState
    {
        return new RuleState(
            ruleId:          $s->ruleId,
            status:          $status,
            breachStreak:    $breach,
            clearStreak:     $clear,
            lastValue:       $value ?? $s->lastValue,
            lastEvaluatedAt: $now,
            changedAt:       $changedAt,
            version:         $s->version,
        );
    }
}
