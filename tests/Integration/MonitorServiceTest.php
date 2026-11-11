<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Application\Ports\MonitorStore;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\RuleState;
use Plugins\Monitors\Infrastructure\Config\MonitorConfig;
use Tests\Plugins\Monitors\Support\FailingQueryDatabase;
use Tests\Plugins\Monitors\Support\FlakyDatabase;
use Tests\Plugins\Monitors\Support\InterleavingDatabase;
use Tests\Plugins\Monitors\Support\Harness;

/** The real SQL (migrated SQLite), the real repository and the real service. Nothing here ran on MySQL/PostgreSQL. */
final class MonitorServiceTest extends TestCase
{
    private ?Harness $h = null;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    protected function tearDown(): void
    {
        $this->h?->discard();
    }

    private function h(): Harness
    {
        return $this->h ?? throw new \LogicException('not set up');
    }

    // ── the oscillation pin, end to end ──────────────────────────────────────

    public function test_a_metric_oscillating_across_the_boundary_sends_exactly_one_page_through_the_whole_stack(): void
    {
        $h = $this->h();
        $h->rule();

        foreach ([110, 90, 110, 90, 110, 90, 110, 90] as $value) {
            $h->metrics->set('api.errors', $value);
            $h->service->evaluate();
            $h->tick();
        }

        $this->assertSame(['fired'], $h->mail->kinds(), 'one mail');
        $this->assertSame(['fired'], $h->webhook->kinds(), 'one webhook');
        $this->assertSame(['fired'], $h->console->kinds());
        $this->assertSame(1, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM monitor_alerts')['n']);
    }

    public function test_it_resolves_once_when_the_metric_really_recovers(): void
    {
        $h = $this->h();
        $h->rule();

        foreach ([110, 90, 70, 70, 120, 60] as $value) {
            $h->metrics->set('api.errors', $value);
            $h->service->evaluate();
            $h->tick();
        }

        $this->assertSame(['fired', 'resolved', 'fired', 'resolved'], $h->mail->kinds());
    }

    // ── overlap pins ─────────────────────────────────────────────────────────

    public function test_an_evaluation_that_finds_the_lock_held_does_nothing(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);

        $held = $h->cache->lock('monitors:evaluate', 240);
        $this->assertTrue($held->acquire());

        $report = $h->service->evaluate();

        $this->assertTrue($report->locked);
        $this->assertSame(0, $report->evaluated);
        $this->assertSame([], $h->mail->sent);

        $held->release();
        $this->assertSame(['api.errors'], $h->service->evaluate()->fired, 'the lock is released and the next run works');
    }

    public function test_the_lock_is_released_even_when_evaluation_blows_up(): void
    {
        $h = $this->h();
        $h->rule();
        $broken = $h->serviceFor(
            new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository(new FailingQueryDatabase($h->db, 'FROM monitor_rules')),
        );

        try {
            $broken->evaluate();
            $this->fail('the database outage should have propagated');
        } catch (\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException) {
        }

        $this->assertTrue($h->cache->lock('monitors:evaluate', 10)->acquire(), 'a crashed run must not hold the lock until its TTL');
    }

    public function test_two_evaluators_that_race_raise_the_alert_once_even_with_no_cache_lock(): void
    {
        $h = $this->h();
        $rule = $h->rule();
        $h->metrics->set('api.errors', 500);

        // A second, independent service over the SAME database, no cache at all:
        // only the optimistic version stands between it and a double page.
        $otherService = $h->serviceFor(new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository($h->db), withCache: false);

        // A decorator that lets the OTHER evaluator run to completion between
        // this evaluator's read of the state and its commit.
        $racing = new class ($h->repo, $otherService) implements MonitorStore {
            public bool $raced = false;

            public function __construct(private readonly MonitorStore $inner, private readonly \Plugins\Monitors\Application\Services\MonitorService $rival) {}

            public function saveRule(Rule $rule, int $now): void { $this->inner->saveRule($rule, $now); }
            public function rule(string $id): ?Rule { return $this->inner->rule($id); }
            public function ruleByName(string $name): ?Rule { return $this->inner->ruleByName($name); }
            public function rules(bool $enabledOnly = false): array { return $this->inner->rules($enabledOnly); }
            public function deleteRule(string $id): bool { return $this->inner->deleteRule($id); }
            public function setEnabled(string $id, bool $enabled, int $now): bool { return $this->inner->setEnabled($id, $enabled, $now); }
            public function states(): array { return $this->inner->states(); }
            public function claimPending(int $now, int $limit, int $staleAfter): array { return $this->inner->claimPending($now, $limit, $staleAfter); }
            public function recordDelivery(string $alertId, array $delivered, bool $complete, bool $abandoned, int $now): void { $this->inner->recordDelivery($alertId, $delivered, $complete, $abandoned, $now); }
            public function backlog(): array { return $this->inner->backlog(); }
            public function pruneAlerts(int $before): int { return $this->inner->pruneAlerts($before); }
            public function commit(RuleState $next, int $expectedVersion, ?Alert $alert): bool { return $this->inner->commit($next, $expectedVersion, $alert); }

            public function state(string $ruleId): ?RuleState
            {
                $state = $this->inner->state($ruleId); // what THIS evaluator saw

                if (!$this->raced) {
                    $this->raced = true;
                    $this->rival->evaluate(); // the rival commits "fired" first
                }

                return $state;
            }
        };

        $first = $h->serviceFor($racing, withCache: false);
        $report = $first->evaluate();

        $this->assertSame(1, $report->lostRace, 'the slower evaluator must lose, not double-fire');
        $this->assertSame([], $report->fired);
        $this->assertSame(1, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM monitor_alerts')['n']);
        $this->assertCount(1, $h->mail->sent, 'exactly one page was sent');
        $this->assertSame($rule->id, $h->mail->sent[0]->ruleId);
    }

    public function test_the_version_check_stops_a_double_fire_when_the_state_row_already_exists(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 10);
        $h->service->evaluate(); // creates the state row (version 1); nothing fires
        $h->tick();
        $h->metrics->set('api.errors', 500);

        // The rival commits "fired" between this evaluator's state read and its write.
        $rival = $h->serviceFor(new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository($h->db), withCache: false);
        $racing = new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository(
            new InterleavingDatabase($h->db, 'FROM monitor_states WHERE rule_id', static fn () => $rival->evaluate()),
        );

        $report = $h->serviceFor($racing, withCache: false)->evaluate();

        $this->assertSame(1, $report->lostRace, 'an UPDATE guarded only by rule_id lets the loser overwrite the winner');
        $this->assertSame(1, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM monitor_alerts')['n']);
        $this->assertCount(1, $h->mail->sent);
    }

    public function test_a_failure_while_recording_the_alert_rolls_the_state_change_back_too(): void
    {
        $h = $this->h();
        $rule = $h->rule();
        $h->metrics->set('api.errors', 10);
        $h->service->evaluate();

        $before = $h->repo->state($rule->id);

        $broken = new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository(new FlakyDatabase($h->db, 'INSERT INTO monitor_alerts'));
        $h->metrics->set('api.errors', 500);
        $h->tick();

        try {
            $h->serviceFor($broken, withCache: false)->evaluate();
            $this->fail('the failed write should have surfaced');
        } catch (\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException) {
        }

        $this->assertFalse($h->db->inTransaction(), 'a failed commit left the transaction open');
        $this->assertEquals($before, $h->repo->state($rule->id), 'the state moved to "firing" with no alert to say so');

        // And the next healthy run still raises it, exactly once.
        $h->service->evaluate();
        $this->assertSame(['fired'], $h->mail->kinds());
    }

    public function test_two_deliverers_that_both_read_the_same_pending_row_cannot_both_send_it(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 1;
        $h->service->evaluate(); // one alert left pending

        $rival = new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository($h->db);
        $rivalWon = [];
        $slow = new \Plugins\Monitors\Infrastructure\Persistence\MonitorRepository(
            new InterleavingDatabase($h->db, 'FROM monitor_alerts WHERE notified_at IS NULL', static function () use ($rival, $h, &$rivalWon): void {
                $rivalWon = $rival->claimPending($h->clock->timestamp(), 10, 300);
            }),
        );

        $claimed = $slow->claimPending($h->clock->timestamp(), 10, 300);

        $this->assertCount(1, $rivalWon, 'the rival claimed it first');
        $this->assertSame([], $claimed, 'the slower deliverer read the row but must not own it');
    }

    // ── ordering: commit, THEN notify ────────────────────────────────────────

    public function test_nothing_is_sent_until_the_transition_is_committed(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);

        $observed = [];
        $h->webhook->onSend = function (Alert $alert) use ($h, &$observed): void {
            $observed = [
                'in_transaction' => $h->db->inTransaction(),
                'alert_row'      => $h->db->queryOne('SELECT id FROM monitor_alerts WHERE id = :id', ['id' => $alert->id]) !== null,
                'state_firing'   => $h->repo->state($alert->ruleId)?->isFiring(),
            ];
        };

        $h->service->evaluate();

        $this->assertSame(['in_transaction' => false, 'alert_row' => true, 'state_firing' => true], $observed);
    }

    // ── per-channel retry ────────────────────────────────────────────────────

    public function test_a_failing_channel_is_retried_and_the_ones_that_worked_are_not_repeated(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 2;

        $h->service->evaluate();
        $this->assertCount(1, $h->mail->sent);
        $this->assertSame([], $h->webhook->sent);
        $this->assertSame(['pending' => 1, 'abandoned' => 0], $h->service->backlog());

        $h->tick();
        $h->service->evaluate(); // webhook fails again; metric still breaching -> no new alert
        $this->assertSame([], $h->webhook->sent);

        $h->tick();
        $h->service->evaluate(); // third try succeeds
        $this->assertCount(1, $h->webhook->sent);

        $this->assertCount(1, $h->mail->sent, 'mail was delivered once and never repeated');
        $this->assertCount(1, $h->console->sent);
        $this->assertSame(['pending' => 0, 'abandoned' => 0], $h->service->backlog());
        $this->assertSame(1, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM monitor_alerts')['n']);
    }

    public function test_an_alert_that_keeps_failing_is_given_up_on_and_reported_not_retried_forever(): void
    {
        $h = new Harness(new MonitorConfig(maxAttempts: 3));
        $this->h = $h;
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 99;

        for ($i = 0; $i < 6; $i++) {
            $h->service->evaluate();
            $h->tick();
        }

        $this->assertSame(['pending' => 0, 'abandoned' => 1], $h->service->backlog());
        $this->assertStringContainsString('1 given up', $h->service->describe());
        $this->assertCount(1, $h->mail->sent);

        $row = $h->db->queryOne('SELECT attempts FROM monitor_alerts');
        $this->assertSame(3, (int) $row['attempts']);
    }

    public function test_pruning_never_deletes_an_alert_that_was_not_delivered(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 1;
        $h->service->evaluate();

        $h->tick('+200 days');

        $this->assertSame(0, $h->service->prune(), 'an undelivered alert is evidence');

        $h->service->deliverPending();
        $this->assertSame(1, $h->service->prune());
    }

    public function test_two_deliverers_cannot_claim_the_same_alert(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 1;
        $h->service->evaluate(); // leaves a pending alert, claim released

        $now = $h->clock->timestamp();
        $first  = $h->repo->claimPending($now, 10, 300);
        $second = $h->repo->claimPending($now, 10, 300);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second, 'the row is claimed; a second deliverer must skip it');

        $this->assertCount(1, $h->repo->claimPending($now + 301, 10, 300), 'a dead deliverer\'s claim goes stale and is reclaimed');
    }

    public function test_an_edit_after_firing_does_not_redirect_a_page_already_in_flight(): void
    {
        $h = $this->h();
        $rule = $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->webhook->failuresLeft = 1;
        $h->service->evaluate();

        $h->service->save(Rule::define(
            id: $rule->id, name: $rule->name, metric: 'http.errors', source: $rule->source, comparison: $rule->comparison,
            fireThreshold: 100, resolveThreshold: 80, mailTo: ['somebody.else@example.com'],
        ));
        $h->service->remove($rule->id);
        $h->tick();
        $h->service->deliverPending();

        $this->assertCount(1, $h->webhook->sent, 'a deleted rule must not strand its pending alert');
        $this->assertSame(['ops@example.com'], $h->webhook->sent[0]->mailTo);
    }

    // ── robustness ───────────────────────────────────────────────────────────

    public function test_one_unreadable_metric_does_not_stop_the_others_or_move_its_own_state(): void
    {
        $h = $this->h();
        $h->rule('a.broken');
        $h->rule('b.fine');
        $h->metrics->failFor('a.broken');
        $h->metrics->set('b.fine', 500);

        $report = $h->service->evaluate();

        $this->assertSame(['b.fine'], $report->fired);
        $this->assertArrayHasKey('a.broken', $report->failed);
        $this->assertNull($h->repo->state($h->repo->ruleByName('a.broken')->id));
    }

    public function test_no_data_leaves_a_firing_rule_firing(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->service->evaluate();

        $h->metrics->set('api.errors', null);
        $h->tick();
        $report = $h->service->evaluate();

        $this->assertSame(1, $report->noData);
        $this->assertSame([], $report->resolved);
        $this->assertTrue($h->repo->state($h->repo->ruleByName('api.errors')->id)->isFiring());
    }

    public function test_a_disabled_rule_is_not_evaluated_and_can_be_re_enabled(): void
    {
        $h = $this->h();
        $rule = $h->rule();
        $h->metrics->set('api.errors', 500);

        $this->assertTrue($h->service->setEnabled($rule->id, false));
        $this->assertSame(0, $h->service->evaluate()->evaluated);

        $h->service->setEnabled($rule->id, true);
        $this->assertSame(['api.errors'], $h->service->evaluate()->fired);
    }

    public function test_rule_names_are_unique_but_a_rule_can_be_replaced_by_id_and_keeps_its_state(): void
    {
        $h = $this->h();
        $rule = $h->rule();
        $h->metrics->set('api.errors', 500);
        $h->service->evaluate();

        try {
            $h->service->save(Rule::define(
                id: Rule::newId(), name: 'api.errors', metric: 'x.y', source: $rule->source,
                comparison: $rule->comparison, fireThreshold: 1.0,
            ));
            $this->fail('a second rule with the same name was accepted');
        } catch (ServiceException $e) {
            $this->assertSame('monitors.rule.name_taken', $e->getMessage());
        }

        $h->service->save(Rule::define(
            id: $rule->id, name: 'api.errors', metric: 'http.errors', source: $rule->source,
            comparison: $rule->comparison, fireThreshold: 200.0, resolveThreshold: 150.0,
        ));

        $this->assertSame(200.0, $h->service->rule($rule->id)->fireThreshold);
        $this->assertTrue($h->service->states()[$rule->id]->isFiring());
    }

    public function test_a_rule_round_trips_through_storage_unchanged(): void
    {
        $h = $this->h();
        $saved = $h->rule('round.trip', [
            'source' => \Plugins\Monitors\Domain\Source::Growth, 'windowSeconds' => 7200,
            'comparison' => \Plugins\Monitors\Domain\Comparison::Gte, 'fireThreshold' => 0.5, 'resolveThreshold' => 0.25,
            'fireAfter' => 3, 'resolveAfter' => 2, 'tenant' => 't-1',
            'severity' => \Plugins\Monitors\Domain\Severity::Critical, 'mailTo' => ['a@b.co', 'c@d.co'],
            'webhook' => false, 'description' => 'growth',
        ]);

        $this->assertEquals($saved, $h->service->ruleByName('round.trip'));
    }

    public function test_describe_names_the_channels_and_the_lock_and_carries_no_address_or_credential(): void
    {
        $h = $this->h();
        $h->rule();
        $line = $h->service->describe();

        $this->assertStringContainsString('monitor.alerting', $line);
        $this->assertStringContainsString('evaluation lock on', $line);
        $this->assertStringNotContainsString('ops@example.com', $line);
        $this->assertDoesNotMatchRegularExpression('/password|secret|dsn|mysql:/i', $line);

        $noCache = $h->serviceFor($h->repo, withCache: false)->describe();
        $this->assertStringContainsString('OFF', $noCache);
    }

    public function test_a_notifier_that_is_not_wired_leaves_the_alert_pending_rather_than_lost(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 500);

        $bare = new \Plugins\Monitors\Application\Services\MonitorService(
            $h->repo, $h->metrics, $h->clock, new MonitorConfig(), ['console' => $h->console],
        );
        $bare->evaluate();

        $this->assertSame(1, $bare->backlog()['pending'], 'mail and webhook could not be sent');
        $this->assertCount(1, $h->console->sent);
    }
}
