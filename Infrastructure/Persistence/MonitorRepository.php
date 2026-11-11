<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Monitors\Application\Ports\MonitorStore;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;
use Plugins\Monitors\Domain\Severity;
use Plugins\Monitors\Domain\Source;

/**
 * DatabasePort only. Take ONE port instance: commit() runs a transaction, and
 * the Database plugin's port is transient (each make() is a new connection).
 */
final class MonitorRepository implements MonitorStore
{
    private const LAYER = 'repository.monitors';

    public function __construct(private readonly DatabasePort $db) {}

    public function saveRule(Rule $rule, int $now): void
    {
        $this->guard('save rule', function () use ($rule, $now): void {
            // created_at is written once: it is not in the update list.
            $this->db->upsert('monitor_rules', [
                'id' => $rule->id, 'name' => $rule->name, 'description' => $rule->description,
                'metric' => $rule->metric, 'source' => $rule->source->value, 'window_seconds' => $rule->windowSeconds,
                'comparison' => $rule->comparison->value,
                'fire_threshold' => $rule->fireThreshold, 'resolve_threshold' => $rule->resolveThreshold,
                'fire_after' => $rule->fireAfter, 'resolve_after' => $rule->resolveAfter,
                'tenant' => $rule->tenant, 'severity' => $rule->severity->value,
                'mail_to' => json_encode($rule->mailTo, JSON_THROW_ON_ERROR),
                'webhook' => (int) $rule->webhook, 'console' => (int) $rule->console, 'enabled' => (int) $rule->enabled,
                'created_at' => $now, 'updated_at' => $now,
            ], ['id'], [
                'name', 'description', 'metric', 'source', 'window_seconds', 'comparison', 'fire_threshold',
                'resolve_threshold', 'fire_after', 'resolve_after', 'tenant', 'severity', 'mail_to', 'webhook',
                'console', 'enabled', 'updated_at',
            ]);
        });
    }

    public function rule(string $id): ?Rule
    {
        $row = $this->guard('read rule', fn () => $this->db->queryOne('SELECT * FROM monitor_rules WHERE id = :id', ['id' => $id]));

        return $row === null ? null : $this->hydrateRule($row);
    }

    public function ruleByName(string $name): ?Rule
    {
        $row = $this->guard('read rule', fn () => $this->db->queryOne('SELECT * FROM monitor_rules WHERE name = :n', ['n' => $name]));

        return $row === null ? null : $this->hydrateRule($row);
    }

    public function rules(bool $enabledOnly = false): array
    {
        $rows = $this->guard('list rules', fn () => $this->db->query(
            'SELECT * FROM monitor_rules' . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY name',
        ));

        return array_map($this->hydrateRule(...), $rows);
    }

    public function deleteRule(string $id): bool
    {
        return $this->guard('delete rule', function () use ($id): bool {
            $this->db->execute('DELETE FROM monitor_states WHERE rule_id = :id', ['id' => $id]);

            return $this->db->execute('DELETE FROM monitor_rules WHERE id = :id', ['id' => $id]) > 0;
        });
    }

    public function setEnabled(string $id, bool $enabled, int $now): bool
    {
        return $this->guard('toggle rule', fn (): bool => $this->db->execute(
            'UPDATE monitor_rules SET enabled = :e, updated_at = :n WHERE id = :id',
            ['e' => (int) $enabled, 'n' => $now, 'id' => $id],
        ) > 0);
    }

    public function state(string $ruleId): ?RuleState
    {
        $row = $this->guard('read state', fn () => $this->db->queryOne('SELECT * FROM monitor_states WHERE rule_id = :id', ['id' => $ruleId]));

        return $row === null ? null : $this->hydrateState($row);
    }

    public function states(): array
    {
        $out = [];
        foreach ($this->guard('list states', fn () => $this->db->query('SELECT * FROM monitor_states')) as $row) {
            $out[$row['rule_id']] = $this->hydrateState($row);
        }

        return $out;
    }

    public function commit(RuleState $next, int $expectedVersion, ?Alert $alert): bool
    {
        $this->db->beginTransaction();

        try {
            if ($expectedVersion === 0) {
                // No row yet. Insert-if-absent: 0 rows means another evaluator
                // created it between our read and now, i.e. we lost the race.
                $won = $this->db->upsert('monitor_states', $this->stateColumns($next, 1), ['rule_id'], []) === 1;
            } else {
                $won = $this->db->execute(
                    'UPDATE monitor_states SET status = :status, breach_streak = :bs, clear_streak = :cs, '
                    . 'last_value = :lv, last_evaluated_at = :le, changed_at = :ca, version = version + 1 '
                    . 'WHERE rule_id = :id AND version = :v',
                    [
                        'status' => $next->status, 'bs' => $next->breachStreak, 'cs' => $next->clearStreak,
                        'lv' => $next->lastValue, 'le' => $next->lastEvaluatedAt, 'ca' => $next->changedAt,
                        'id' => $next->ruleId, 'v' => $expectedVersion,
                    ],
                ) === 1;
            }

            if (!$won) {
                $this->db->rollback();

                return false;
            }

            if ($alert !== null) {
                $this->db->execute(
                    'INSERT INTO monitor_alerts (id, rule_id, rule_name, metric, severity, kind, value, threshold, '
                    . 'occurred_at, tenant, mail_to, webhook, console, delivered, attempts) '
                    . 'VALUES (:id, :rid, :rn, :m, :sev, :k, :v, :t, :at, :ten, :mail, :wh, :co, :d, 0)',
                    [
                        'id' => $alert->id, 'rid' => $alert->ruleId, 'rn' => $alert->ruleName, 'm' => $alert->metric,
                        'sev' => $alert->severity->value, 'k' => $alert->kind->value, 'v' => $alert->value,
                        't' => $alert->threshold, 'at' => $alert->occurredAt, 'ten' => $alert->tenant,
                        'mail' => json_encode($alert->mailTo, JSON_THROW_ON_ERROR),
                        'wh' => (int) $alert->webhook, 'co' => (int) $alert->console, 'd' => '[]',
                    ],
                );
            }

            $this->db->commit();

            return true;
        } catch (\PDOException $e) {
            $this->abort();

            throw new RepositoryException('Failed to commit a monitor evaluation', layer: self::LAYER, previous: $e);
        } catch (\Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    public function claimPending(int $now, int $limit, int $staleAfter): array
    {
        $stale = $now - $staleAfter;

        $rows = $this->guard('list pending alerts', fn () => $this->db->query(
            // No `attempts < max` here, on purpose: giving up is recorded by
            // recordDelivery() (abandoned = 1). A cap in this query as well would
            // strand an alert that is neither delivered nor flagged abandoned the
            // moment the operator LOWERS the limit.
            'SELECT * FROM monitor_alerts WHERE notified_at IS NULL AND abandoned = 0 '
            . 'AND (claimed_at IS NULL OR claimed_at < :stale) ORDER BY occurred_at, id LIMIT ' . max(1, $limit),
            ['stale' => $stale],
        ));

        $claimed = [];
        foreach ($rows as $row) {
            // The conditional UPDATE is the claim: of two deliverers that read
            // the same row, exactly one sees a row count of 1.
            $won = $this->guard('claim alert', fn (): int => $this->db->execute(
                'UPDATE monitor_alerts SET claimed_at = :now WHERE id = :id AND notified_at IS NULL '
                . 'AND (claimed_at IS NULL OR claimed_at < :stale)',
                ['now' => $now, 'id' => $row['id'], 'stale' => $stale],
            ));

            if ($won === 1) {
                $claimed[] = $this->hydrateAlert($row);
            }
        }

        return $claimed;
    }

    public function recordDelivery(string $alertId, array $delivered, bool $complete, bool $abandoned, int $now): void
    {
        $this->guard('record delivery', fn () => $this->db->execute(
            'UPDATE monitor_alerts SET delivered = :d, attempts = attempts + 1, claimed_at = NULL, '
            . 'notified_at = :n, abandoned = :a WHERE id = :id',
            [
                'd' => json_encode(array_values(array_unique($delivered)), JSON_THROW_ON_ERROR),
                'n' => $complete ? $now : null, 'a' => (int) $abandoned, 'id' => $alertId,
            ],
        ));
    }

    public function backlog(): array
    {
        $row = $this->guard('count backlog', fn () => $this->db->queryOne(
            'SELECT COALESCE(SUM(CASE WHEN notified_at IS NULL AND abandoned = 0 THEN 1 ELSE 0 END), 0) AS p, '
            . 'COALESCE(SUM(CASE WHEN abandoned = 1 THEN 1 ELSE 0 END), 0) AS a FROM monitor_alerts',
        ));

        return ['pending' => (int) ($row['p'] ?? 0), 'abandoned' => (int) ($row['a'] ?? 0)];
    }

    public function pruneAlerts(int $before): int
    {
        // Only SETTLED alerts: one still owed a channel is evidence, and deleting
        // it would turn "the page never arrived" into "no page was ever raised".
        return $this->guard('prune alerts', fn (): int => $this->db->execute(
            'DELETE FROM monitor_alerts WHERE occurred_at < :t AND (notified_at IS NOT NULL OR abandoned = 1)',
            ['t' => $before],
        ));
    }

    /** @return array<string, mixed> */
    private function stateColumns(RuleState $s, int $version): array
    {
        return [
            'rule_id' => $s->ruleId, 'status' => $s->status, 'breach_streak' => $s->breachStreak,
            'clear_streak' => $s->clearStreak, 'last_value' => $s->lastValue,
            'last_evaluated_at' => $s->lastEvaluatedAt, 'changed_at' => $s->changedAt, 'version' => $version,
        ];
    }

    /** @param array<string, mixed> $r */
    private function hydrateRule(array $r): Rule
    {
        return Rule::reconstitute(
            (string) $r['id'], (string) $r['name'], (string) $r['description'], (string) $r['metric'],
            Source::from((string) $r['source']), (int) $r['window_seconds'], Comparison::from((string) $r['comparison']),
            (float) $r['fire_threshold'], (float) $r['resolve_threshold'], (int) $r['fire_after'], (int) $r['resolve_after'],
            $r['tenant'] === null ? null : (string) $r['tenant'], Severity::from((string) $r['severity']),
            $this->decodeList($r['mail_to']), (bool) $r['webhook'], (bool) $r['console'], (bool) $r['enabled'],
        );
    }

    /** @param array<string, mixed> $r */
    private function hydrateState(array $r): RuleState
    {
        return new RuleState(
            (string) $r['rule_id'], (string) $r['status'], (int) $r['breach_streak'], (int) $r['clear_streak'],
            $r['last_value'] === null ? null : (float) $r['last_value'], (int) $r['last_evaluated_at'],
            (int) $r['changed_at'], (int) $r['version'],
        );
    }

    /** @param array<string, mixed> $r */
    private function hydrateAlert(array $r): Alert
    {
        return new Alert(
            (string) $r['id'], (string) $r['rule_id'], (string) $r['rule_name'], (string) $r['metric'],
            Severity::from((string) $r['severity']), AlertKind::from((string) $r['kind']),
            (float) $r['value'], (float) $r['threshold'], (int) $r['occurred_at'],
            $r['tenant'] === null ? null : (string) $r['tenant'], $this->decodeList($r['mail_to']),
            (bool) $r['webhook'], (bool) $r['console'], $this->decodeList($r['delivered']), (int) $r['attempts'],
        );
    }

    /** @return list<string> */
    private function decodeList(mixed $json): array
    {
        $data = json_decode((string) $json, true);

        return \is_array($data) ? array_values(array_map('strval', $data)) : [];
    }

    private function abort(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollback();
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(string $what, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to {$what}", layer: self::LAYER, previous: $e);
        }
    }
}
