<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

enum Comparison: string
{
    case Gt  = 'gt';
    case Gte = 'gte';
    case Lt  = 'lt';
    case Lte = 'lte';

    /** Is `$value` on the bad side of `$threshold`? */
    public function breaches(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Gt  => $value > $threshold,
            self::Gte => $value >= $threshold,
            self::Lt  => $value < $threshold,
            self::Lte => $value <= $threshold,
        };
    }

    /** Does a rule's resolve threshold sit on the safe side of (or at) its fire threshold? */
    public function resolveIsOnTheSafeSide(float $fire, float $resolve): bool
    {
        return match ($this) {
            self::Gt, self::Gte => $resolve <= $fire,
            self::Lt, self::Lte => $resolve >= $fire,
        };
    }
}
