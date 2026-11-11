<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Integration;

use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use PHPUnit\Framework\TestCase;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Application\Jobs\EvaluateMonitorsJob;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Source;
use Plugins\Monitors\Provider;
use Plugins\Usage\API\Contracts\UsageContract;
use Tests\Plugins\Monitors\Support\RecordingListener;
use Tests\Plugins\Monitors\Support\RecordingMailer;
use Tests\Plugins\Monitors\Support\StubUsage;

/**
 * The real Provider::register() against a real container, a migrated database,
 * the published Usage contract (stubbed) and the real EventBus — including the
 * hand-off a worker performs: job -> contract -> usage -> state -> mail + event.
 */
final class ProviderWiringTest extends TestCase
{
    private ?MigratedSqlite $db = null;
    private ModuleContainer $container;
    private StubUsage $usage;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        $this->db = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'monitors-wiring');

        $this->usage  = new StubUsage();
        $this->mailer = new RecordingMailer();
        RecordingListener::$events = [];
        RecordingListener::$throw  = false;

        $core = new CoreContainer();
        $core->instance(DatabasePort::class, $this->db);
        $core->instance(ClockPort::class, new FrozenClock('2026-03-05 12:00:00'));
        $core->instance(LoggerPort::class, new FakeLogger());
        $bus = new EventBus($core);
        $bus->subscribe('monitor.alert.fired', RecordingListener::class);
        $core->instance(EventBus::class, $bus);

        $this->container = new ModuleContainer($core);

        // The other two modules' PUBLIC contracts, as the loader would have
        // registered them because this module requires them.
        $this->container->instance(UsageContract::class, $this->usage);
        $this->container->instance(MailerContract::class, $this->mailer);

        $this->container->setScope('monitor.alerting');
        (new Provider())->register($this->container);
        $this->container->setScope('');
    }

    protected function tearDown(): void
    {
        $this->db?->discard();
    }

    public function test_the_job_a_worker_would_run_evaluates_fires_and_delivers_over_every_channel(): void
    {
        $monitors = $this->container->make(MonitorContract::class);
        $monitors->save(Rule::define(
            id: Rule::newId(), name: 'api.errors', metric: 'http.errors', source: Source::Sum,
            comparison: Comparison::Gt, fireThreshold: 100, resolveThreshold: 80, mailTo: ['ops@example.com'],
        ));
        $this->usage->sum = 250;

        // make() with NO scope, as WorkerLoop does: an internal binding would throw here.
        $job = $this->container->make(EvaluateMonitorsJob::class);
        $result = $job->handle(new JobPayload('j1', 'monitors.evaluate', [], 'monitors', 0, 1, new \DateTimeImmutable(), ''));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->data()['fired']);
        $this->assertSame(1, $result->data()['delivered']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertCount(1, RecordingListener::$events);
        $this->assertSame('monitor.alert.fired', RecordingListener::$events[0]->name());
        $this->assertSame('sum', $this->usage->calls[0][0]);
    }

    public function test_internals_cannot_be_resolved_from_outside_the_module(): void
    {
        $this->expectException(\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException::class);

        $this->container->make(\Plugins\Monitors\Application\Ports\MonitorStore::class);
    }

    public function test_the_mail_channel_is_simply_absent_when_no_mailer_is_registered(): void
    {
        $core = new CoreContainer();
        $core->instance(DatabasePort::class, $this->db);
        $core->instance(EventBus::class, new EventBus($core));
        $c = new ModuleContainer($core);
        $c->instance(UsageContract::class, $this->usage);
        $c->setScope('monitor.alerting');
        (new Provider())->register($c);
        $c->setScope('');

        $this->assertStringContainsString('channels [console, webhook]', $c->make(MonitorContract::class)->describe());
    }

    public function test_provider_mirrors_the_manifest(): void
    {
        $p = new Provider();

        $this->assertSame('monitor.alerting', $p->solves());
        $this->assertSame([MonitorContract::class], $p->exposes());
    }
}
