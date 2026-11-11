<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use Plugins\Usage\API\Contracts\UsageContract;
use Plugins\Usage\Domain\Growth;
use Plugins\Usage\Domain\Resolution;
use Plugins\Usage\Domain\RollupReport;

/** The published Usage contract, answering from a script and recording how it was asked. */
final class StubUsage implements UsageContract
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public int $sum = 0;
    public ?float $latest = null;
    public Growth $growth;

    public function __construct()
    {
        $this->growth = new Growth(0, 0);
    }

    public function count(string $metric, int $by = 1): void {}
    public function gauge(string $metric, int|float $value): void {}
    public function flush(): bool { return false; }

    public function sum(string $metric, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $tenant = null): int
    {
        $this->calls[] = ['sum', $metric, $from->getTimestamp(), $to->getTimestamp(), $tenant];

        return $this->sum;
    }

    public function latest(string $metric, ?string $tenant = null): ?float
    {
        $this->calls[] = ['latest', $metric, $tenant];

        return $this->latest;
    }

    public function series(string $metric, \DateTimeImmutable $from, \DateTimeImmutable $to, Resolution $resolution = Resolution::Hour, ?string $tenant = null): array
    {
        return [];
    }

    public function growth(string $metric, int $windowSeconds, ?string $tenant = null): Growth
    {
        $this->calls[] = ['growth', $metric, $windowSeconds, $tenant];

        return $this->growth;
    }

    public function rollup(): RollupReport { return new RollupReport(); }
    public function describe(): string { return 'stub'; }
}
