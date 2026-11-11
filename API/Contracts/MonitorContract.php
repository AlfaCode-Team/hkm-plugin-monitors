<?php

declare(strict_types=1);

namespace Plugins\Monitors\API\Contracts;

use Plugins\Monitors\Domain\EvaluationReport;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;

/**
 * Rules over usage metrics, firing and resolving with hysteresis.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHAT A NOTIFICATION IS
 * ──────────────────────────────────────────────────────────────────────────
 * Only a STATE CHANGE is notified: ok -> firing, and firing -> ok. A rule that
 * stays firing sends nothing further, and one that is merely above threshold
 * for a while (see Rule's streaks) sends nothing at all.
 *
 * A transition is committed together with the alert row that records it, and
 * only AFTER that commit is anything sent. A channel that fails is retried on
 * the next run, per channel, so a broken webhook does not re-send the mail.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE WEBHOOK CHANNEL IS AN EVENT
 * ──────────────────────────────────────────────────────────────────────────
 * `webhook.delivery` has no "send this" method: it subscribes to the EventBus
 * and delivers events to endpoints that registered a pattern. So this plugin
 * dispatches `monitor.alert.fired` / `monitor.alert.resolved`, and an endpoint
 * registered for `monitor.alert.*` (or `monitor.**`) receives them. With no
 * endpoint registered the channel "succeeds" and nothing is sent anywhere;
 * `describe()` says whether anything is subscribed.
 */
interface MonitorContract
{
    /**
     * Create or replace a rule (by id). A rule that is replaced keeps its state.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException when the name belongs to another rule
     */
    public function save(Rule $rule): void;

    /** @return list<Rule> */
    public function rules(): array;

    public function rule(string $id): ?Rule;

    public function ruleByName(string $name): ?Rule;

    public function remove(string $id): bool;

    public function setEnabled(string $id, bool $enabled): bool;

    /**
     * Evaluate every enabled rule once, commit the transitions, then deliver.
     *
     * Safe to call concurrently: an evaluator that loses the race for a rule
     * skips it, so an alert cannot fire twice. Never throws for one rule's
     * failure — that rule is listed in the report and the rest are evaluated.
     */
    public function evaluate(): EvaluationReport;

    /**
     * Send alerts whose channels have not all been delivered.
     *
     * @return int alerts fully delivered by this call
     */
    public function deliverPending(?int $limit = null): int;

    /** @return array<string, RuleState> by rule id */
    public function states(): array;

    /** Alerts still waiting on at least one channel, and those given up on. */
    public function backlog(): array;

    /** Delete settled alerts past the retention window. */
    public function prune(): int;

    /** One line for status output. Never an address list, a secret or a DSN. */
    public function describe(): string;
}
