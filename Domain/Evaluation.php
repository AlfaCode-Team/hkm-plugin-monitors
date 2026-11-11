<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

final readonly class Evaluation
{
    public function __construct(
        public RuleState $state,
        public ?Transition $transition,
    ) {}
}
