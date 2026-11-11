<?php

declare(strict_types=1);

namespace Plugins\Monitors\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Application\Ports\MetricSource;
use Plugins\Monitors\Application\Ports\MonitorStore;
use Plugins\Monitors\Application\Ports\Notifier;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Domain\EvaluationReport;
use Plugins\Monitors\Domain\Hysteresis;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;
use Plugins\Monitors\Infrastructure\Config\MonitorConfig;

/**
 * Three independent guards against an overlapping evaluation double-firing an
 * alert, because it is the failure that is invisible until it pages someone:
 *
 *   1. the schedule entry's `withoutOverlapping: true` (module.json — pinned by
 *      ScheduleDeclarationTest against the real compiler and scheduler);
 *   2. a CachePort lock around evaluate(), when a cache is bound;
 *   3. an optimistic version on each rule's state row, which holds even with no
 *      cache and no scheduler — the loser of a race writes nothing.
 *
 * The state change and its alert row commit together (MonitorStore::commit()).
 * Notifications go out AFTER that, in deliverPending() — never inside the
 * transaction, never before it.
 */
final class MonitorService implements MonitorContract
{
    private const LOCK = 'monitors:evaluate';

    /** @param array<string, Notifier> $notifiers by channel */
    public function __construct(
        private readonly MonitorStore $store,
        private readonly MetricSource $metrics,
        private readonly ClockPort $clock,
        private readonly MonitorConfig $config,
        private readonly array $notifiers = [],
        private readonly ?CachePort $cache = null,
        private readonly ?LoggerPort $logger = null,
    ) {}

    public function save(Rule $rule): void
    {
        $existing = $this->store->ruleByName($rule->name);

        if ($existing !== null && $existing->id !== $rule->id) {
            throw new ServiceException(
                'monitors.rule.name_taken',
                layer: 'service.monitors',
                context: ['name' => $rule->name],
            );
        }

        $this->store->saveRule($rule, $this->clock->timestamp());
    }

    public function rules(): array
    {
        return $this->store->rules();
    }

    public function rule(string $id): ?Rule
    {
        return $this->store->rule($id);
    }

    public function ruleByName(string $name): ?Rule
    {
        return $this->store->ruleByName($name);
    }

    public function remove(string $id): bool
    {
        return $this->store->deleteRule($id);
    }

    public function setEnabled(string $id, bool $enabled): bool
    {
        return $this->store->setEnabled($id, $enabled, $this->clock->timestamp());
    }

    public function evaluate(): EvaluationReport
    {
        $lock = $this->cache?->lock(self::LOCK, $this->config->lockSeconds);

        if ($lock !== null && !$lock->acquire()) {
            return new EvaluationReport(locked: true);
        }

        try {
            return $this->evaluateAll();
        } finally {
            $lock?->release();
        }
    }

    private function evaluateAll(): EvaluationReport
    {
        $now = $this->clock->timestamp();

        $evaluated = $noData = $lostRace = 0;
        $fired = $resolved = [];
        $failed = [];

        foreach ($this->store->rules(enabledOnly: true) as $rule) {
            try {
                $value = $this->metrics->read($rule, $now);
            } catch (\Throwable $e) {
                // One unreadable metric must not stop the other rules from
                // being evaluated — and it must not move this rule's state:
                // "could not read" is not "fine".
                $failed[$rule->name] = $e->getMessage();
                $this->logger?->warning('Monitor rule [{rule}] could not be read: {message}', [
                    'rule' => $rule->name, 'message' => $e->getMessage(),
                ]);

                continue;
            }

            $state = $this->store->state($rule->id) ?? new RuleState($rule->id);
            $step  = Hysteresis::step($rule, $state, $value, $now);

            $alert = $step->transition === null ? null : new Alert(
                id:         bin2hex(random_bytes(16)),
                ruleId:     $rule->id,
                ruleName:   $rule->name,
                metric:     $rule->metric,
                severity:   $rule->severity,
                kind:       $step->transition->kind,
                value:      $step->transition->value,
                threshold:  $step->transition->threshold,
                occurredAt: $step->transition->at,
                tenant:     $rule->tenant,
                mailTo:     $rule->mailTo,
                webhook:    $rule->webhook,
                console:    $rule->console,
            );

            if (!$this->store->commit($step->state, $state->version, $alert)) {
                $lostRace++;

                continue;
            }

            $evaluated++;
            if ($value === null) {
                $noData++;
            }

            if ($step->transition?->kind === AlertKind::Fired) {
                $fired[] = $rule->name;
            } elseif ($step->transition?->kind === AlertKind::Resolved) {
                $resolved[] = $rule->name;
            }
        }

        // AFTER every commit above. Nothing is sent from inside a transaction.
        $delivered = $this->deliverPending();

        return new EvaluationReport($evaluated, $fired, $resolved, $noData, $lostRace, $failed, $delivered);
    }

    public function deliverPending(?int $limit = null): int
    {
        $now   = $this->clock->timestamp();
        $done  = 0;
        $limit = $limit ?? $this->config->batch;

        foreach ($this->store->claimPending($now, $limit, $this->config->claimTimeout) as $alert) {
            $delivered = $alert->delivered;
            $failures  = [];

            foreach ($alert->remaining() as $channel) {
                $notifier = $this->notifiers[$channel] ?? null;

                if ($notifier === null) {
                    $failures[$channel] = 'no notifier is wired for this channel';

                    continue;
                }

                try {
                    $notifier->send($alert);
                    $delivered[] = $channel;
                } catch (\Throwable $e) {
                    $failures[$channel] = $e->getMessage();
                }
            }

            $complete  = $failures === [];
            $abandoned = !$complete && $alert->attempts + 1 >= $this->config->maxAttempts;

            $this->store->recordDelivery($alert->id, $delivered, $complete, $abandoned, $now);

            if ($failures !== []) {
                $this->logger?->error('Alert [{rule}] {outcome}: {channels}', [
                    'rule'     => $alert->ruleName,
                    'outcome'  => $abandoned ? 'was given up on' : 'will be retried',
                    'channels' => implode('; ', array_map(static fn ($c, $m) => "{$c}: {$m}", array_keys($failures), $failures)),
                ]);
            }

            $complete && $done++;
        }

        return $done;
    }

    public function states(): array
    {
        return $this->store->states();
    }

    public function backlog(): array
    {
        return $this->store->backlog();
    }

    public function prune(): int
    {
        return $this->store->pruneAlerts($this->clock->timestamp() - $this->config->retainDays * 86400);
    }

    public function describe(): string
    {
        $rules   = $this->store->rules();
        $enabled = \count(array_filter($rules, static fn (Rule $r): bool => $r->enabled));
        $backlog = $this->store->backlog();

        return sprintf(
            'monitor.alerting — %d rule(s), %d enabled, channels [%s], %d alert(s) pending, %d given up, evaluation lock %s',
            \count($rules),
            $enabled,
            implode(', ', array_keys($this->notifiers)),
            $backlog['pending'],
            $backlog['abandoned'],
            $this->cache === null ? 'OFF (no CachePort; version check only)' : 'on',
        );
    }
}
