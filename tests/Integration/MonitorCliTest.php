<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Integration;

use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\PhpIoCli\BufferIO;
use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Infrastructure\Cli\MonitorsAddCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsEvaluateCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsPruneCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsStatusCommand;
use Tests\Plugins\Monitors\Support\Harness;

/** Every command, executed, against a migrated SQLite database. */
final class MonitorCliTest extends TestCase
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

    /** @param list<string> $tokens */
    private function add(array $tokens): array
    {
        $io   = new BufferIO();
        $code = (new MonitorsAddCommand($this->h()->service))->execute($tokens, $io);

        return [$code, $io->getOutput()];
    }

    public function test_add_creates_a_rule_that_the_service_then_reads_back(): void
    {
        [$code, $out] = $this->add([
            'api.errors', '--metric=http.errors', '--op=gt', '--fire=100', '--resolve=80', '--window=3600',
            '--fire-after=2', '--severity=critical', '--mail=ops@example.com,dev@example.com', '--no-console',
        ]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('created', $out);
        $this->assertStringNotContainsString('flap', $out);

        $rule = $this->h()->service->ruleByName('api.errors');
        $this->assertSame(100.0, $rule->fireThreshold);
        $this->assertSame(80.0, $rule->resolveThreshold);
        $this->assertSame(2, $rule->fireAfter);
        $this->assertSame(['mail', 'webhook'], $rule->channels());
    }

    public function test_add_warns_about_a_rule_that_will_flap(): void
    {
        [$code, $out] = $this->add(['x.y', '--metric=http.errors', '--fire=10']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('no hysteresis', $out);
    }

    public function test_add_refuses_nonsense_with_a_message_and_stores_nothing(): void
    {
        foreach ([
            ['bad', '--metric=http.errors', '--fire=100', '--resolve=120'],          // resolve on the wrong side
            ['bad', '--metric=http.errors'],                                         // no threshold
            ['bad', '--metric=http.errors', '--fire=abc'],
            ['bad', '--metric=http.errors', '--fire=1', '--op=sideways'],            // not an enum case
            ['bad', '--metric=http.errors', '--fire=1', '--window=90'],
        ] as $tokens) {
            [$code, $out] = $this->add($tokens);

            $this->assertSame(1, $code, implode(' ', $tokens));
            $this->assertNotSame('', trim($out));
        }

        $this->assertSame([], $this->h()->service->rules());
    }

    public function test_add_refuses_a_name_that_is_taken(): void
    {
        $this->add(['dup', '--metric=http.errors', '--fire=1']);
        [$code, $out] = $this->add(['dup', '--metric=http.errors', '--fire=1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('name_taken', $out);
    }

    public function test_status_is_green_with_a_quiet_rule_and_red_when_one_is_firing(): void
    {
        $h = $this->h();
        $h->rule();
        $h->metrics->set('api.errors', 10);
        $h->service->evaluate();

        $io   = new BufferIO();
        $code = (new MonitorsStatusCommand($h->service))->execute([], $io);
        $this->assertSame(0, $code, $io->getOutput());
        $this->assertStringContainsString('api.errors', $io->getOutput());
        $this->assertStringContainsString('ok', $io->getOutput());

        $h->metrics->set('api.errors', 500);
        $h->tick();
        $h->service->evaluate();

        $io   = new BufferIO();
        $code = (new MonitorsStatusCommand($h->service))->execute([], $io);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('firing', $io->getOutput());
        $this->assertStringNotContainsString('ops@example.com', $io->getOutput(), 'status must not print recipient addresses');
    }

    public function test_status_is_red_for_an_undelivered_alert_even_when_the_rule_resolved(): void
    {
        $h = $this->h();
        $h->rule();
        $h->webhook->failuresLeft = 99;
        $h->metrics->set('api.errors', 500);
        $h->service->evaluate();
        $h->metrics->set('api.errors', 10);
        $h->tick();
        $h->service->evaluate();

        $io = new BufferIO();

        $this->assertSame(1, (new MonitorsStatusCommand($h->service))->execute([], $io));
        $this->assertStringContainsString('waiting on a channel', $io->getOutput());
    }

    public function test_evaluate_queues_the_job_the_schedule_queues(): void
    {
        $queue = new FakeQueue();
        $io    = new BufferIO();

        $code = (new MonitorsEvaluateCommand($queue, 'monitors'))->execute([], $io);

        $this->assertSame(0, $code);
        $this->assertSame(1, $queue->pushCount('monitors.evaluate'));
        $this->assertSame('monitors', $queue->pushed[0]['queue']);
    }

    public function test_evaluate_says_so_when_there_is_no_queue(): void
    {
        $io = new BufferIO();

        $this->assertSame(1, (new MonitorsEvaluateCommand(null))->execute([], $io));
        $this->assertStringContainsString('No QueuePort', $io->getOutput());
    }

    public function test_prune_runs_and_is_quiet_on_request(): void
    {
        $io = new BufferIO();
        $this->assertSame(0, (new MonitorsPruneCommand($this->h()->service))->execute([], $io));
        $this->assertStringContainsString('Nothing to prune', $io->getOutput());

        $quiet = new BufferIO();
        (new MonitorsPruneCommand($this->h()->service))->execute(['--quiet-when-idle'], $quiet);
        $this->assertSame('', trim($quiet->getOutput()));
    }
}
