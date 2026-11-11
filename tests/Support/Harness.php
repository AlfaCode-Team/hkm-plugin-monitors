<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use AlfacodeTeam\Ground\Fakes\FakeCache;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use Plugins\Monitors\Application\Services\MonitorService;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Source;
use Plugins\Monitors\Infrastructure\Config\MonitorConfig;
use Plugins\Monitors\Infrastructure\Persistence\MonitorRepository;

/** A real migrated SQLite database, the real repository and service, scripted metrics and recording channels. */
final class Harness
{
    public MigratedSqlite $db;
    public MonitorRepository $repo;
    public FrozenClock $clock;
    public ScriptedMetricSource $metrics;
    public RecordingNotifier $mail;
    public RecordingNotifier $webhook;
    public RecordingNotifier $console;
    public FakeCache $cache;
    public MonitorService $service;

    public function __construct(?MonitorConfig $config = null, bool $withCache = true, ?\Plugins\Monitors\Application\Ports\MonitorStore $store = null)
    {
        $this->db      = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'monitors');
        $this->repo    = new MonitorRepository($this->db);
        $this->clock   = new FrozenClock('2026-03-05 12:00:00');
        $this->metrics = new ScriptedMetricSource();
        $this->mail    = new RecordingNotifier('mail');
        $this->webhook = new RecordingNotifier('webhook');
        $this->console = new RecordingNotifier('console');
        $this->cache   = new FakeCache($this->clock);

        $this->service = $this->serviceFor($store ?? $this->repo, $withCache, $config);
    }

    public function serviceFor(\Plugins\Monitors\Application\Ports\MonitorStore $store, bool $withCache = true, ?MonitorConfig $config = null): MonitorService
    {
        return new MonitorService(
            store:     $store,
            metrics:   $this->metrics,
            clock:     $this->clock,
            config:    $config ?? new MonitorConfig(),
            notifiers: ['mail' => $this->mail, 'webhook' => $this->webhook, 'console' => $this->console],
            cache:     $withCache ? $this->cache : null,
        );
    }

    /** @param array<string, mixed> $o */
    public function rule(string $name = 'api.errors', array $o = []): Rule
    {
        $rule = Rule::define(...array_merge([
            'id' => Rule::newId(), 'name' => $name, 'metric' => 'http.errors', 'source' => Source::Sum,
            'comparison' => Comparison::Gt, 'fireThreshold' => 100.0, 'resolveThreshold' => 80.0,
            'mailTo' => ['ops@example.com'],
        ], $o));

        $this->service->save($rule);

        return $rule;
    }

    public function tick(string $interval = '+5 minutes'): void
    {
        $this->clock->travel($interval);
    }

    public function discard(): void
    {
        $this->db->discard();
    }
}
