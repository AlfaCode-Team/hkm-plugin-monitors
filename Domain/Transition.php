<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/** A rule changing state. The only thing that is ever notified. */
final readonly class Transition
{
    public function __construct(
        public AlertKind $kind,
        public float $value,
        /** The threshold that was crossed: the fire one for Fired, the resolve one for Resolved. */
        public float $threshold,
        public int $at,
    ) {}
}
