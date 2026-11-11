<?php

declare(strict_types=1);

namespace Plugins\Monitors\Application\Ports;

use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;

interface MonitorStore
{
    public function saveRule(Rule $rule, int $now): void;

    public function rule(string $id): ?Rule;

    public function ruleByName(string $name): ?Rule;

    /** @return list<Rule> */
    public function rules(bool $enabledOnly = false): array;

    /** Deletes the rule AND its state. Alerts already raised are kept: they are history. */
    public function deleteRule(string $id): bool;

    public function setEnabled(string $id, bool $enabled, int $now): bool;

    public function state(string $ruleId): ?RuleState;

    /** @return array<string, RuleState> */
    public function states(): array;

    /**
     * Store the new state, and the alert that records its transition, in ONE
     * transaction — only if nobody else has evaluated this rule since
     * `$expectedVersion` was read (0 = no state row yet).
     *
     * @return bool false when another evaluator got there first; NOTHING was written
     */
    public function commit(RuleState $next, int $expectedVersion, ?Alert $alert): bool;

    /**
     * Alerts with channels still owed, claimed for this caller.
     *
     * @return list<Alert>
     */
    public function claimPending(int $now, int $limit, int $staleAfter): array;

    /** @param list<string> $delivered */
    public function recordDelivery(string $alertId, array $delivered, bool $complete, bool $abandoned, int $now): void;

    /** @return array{pending: int, abandoned: int} */
    public function backlog(): array;

    public function pruneAlerts(int $before): int;
}
