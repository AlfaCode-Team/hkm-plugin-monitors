<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/**
 * Where one rule is in its fire/resolve cycle.
 *
 * `version` is the optimistic-lock token. Two evaluators that read the same
 * state and both decide "fire" would otherwise both raise the alert; the store
 * accepts only the one that still holds the version it read, so the loser
 * learns it lost instead of double-paging.
 */
final readonly class RuleState
{
    public const OK     = 'ok';
    public const FIRING = 'firing';

    public function __construct(
        public string $ruleId,
        public string $status = self::OK,
        public int $breachStreak = 0,
        public int $clearStreak = 0,
        public ?float $lastValue = null,
        public int $lastEvaluatedAt = 0,
        public int $changedAt = 0,
        public int $version = 0,
    ) {}

    public function isFiring(): bool
    {
        return $this->status === self::FIRING;
    }
}
