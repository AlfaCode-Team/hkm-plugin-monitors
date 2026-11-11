<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/** What a rule reads from the metric. */
enum Source: string
{
    /** Counter total over the last `windowSeconds`. */
    case Sum = 'sum';

    /** The most recently reported level of a gauge. */
    case Gauge = 'gauge';

    /**
     * Counter growth: (this window - the window before) / the window before,
     * as a fraction (0.5 = +50%). Has no value when the earlier window was 0.
     */
    case Growth = 'growth';
}
