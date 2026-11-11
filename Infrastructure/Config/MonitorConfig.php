<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Config;

final readonly class MonitorConfig
{
    public function __construct(
        public int $maxAttempts = 5,
        /** Seconds before a claimed-but-unfinished alert may be taken by another deliverer. */
        public int $claimTimeout = 300,
        public int $retainDays = 90,
        /** TTL of the evaluation lock. Longer than a normal run, shorter than the schedule's interval. */
        public int $lockSeconds = 240,
        public int $batch = 50,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            maxAttempts:  max(1, (int) (env('MONITORS_MAX_ATTEMPTS') ?: 5)),
            claimTimeout: max(30, (int) (env('MONITORS_CLAIM_TIMEOUT') ?: 300)),
            retainDays:   max(1, (int) (env('MONITORS_RETAIN_DAYS') ?: 90)),
            lockSeconds:  max(30, (int) (env('MONITORS_LOCK_SECONDS') ?: 240)),
            batch:        max(1, (int) (env('MONITORS_BATCH') ?: 50)),
        );
    }
}
