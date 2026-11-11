<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

final readonly class EvaluationReport
{
    /**
     * @param list<string>          $fired      rule names that started firing
     * @param list<string>          $resolved   rule names that resolved
     * @param array<string, string> $failed     rule name => why it could not be read
     */
    public function __construct(
        public int $evaluated = 0,
        public array $fired = [],
        public array $resolved = [],
        public int $noData = 0,
        public int $lostRace = 0,
        public array $failed = [],
        public int $delivered = 0,
        public bool $locked = false,
    ) {}

    public function isIdle(): bool
    {
        return $this->evaluated === 0 && !$this->locked && $this->failed === [] && $this->delivered === 0;
    }
}
