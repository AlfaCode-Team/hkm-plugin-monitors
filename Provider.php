<?php

declare(strict_types=1);

namespace Plugins\Monitors;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Application\Jobs\EvaluateMonitorsJob;
use Plugins\Monitors\Application\Ports\MetricSource;
use Plugins\Monitors\Application\Ports\MonitorStore;
use Plugins\Monitors\Application\Services\MonitorService;
use Plugins\Monitors\Infrastructure\Cli\MonitorsAddCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsEvaluateCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsPruneCommand;
use Plugins\Monitors\Infrastructure\Cli\MonitorsStatusCommand;
use Plugins\Monitors\Infrastructure\Config\MonitorConfig;
use Plugins\Monitors\Infrastructure\Gateways\UsageMetricSource;
use Plugins\Monitors\Infrastructure\Gateways\WorkerOnlyMetricSource;
use Plugins\Monitors\Infrastructure\Notifiers\ConsoleNotifier;
use Plugins\Monitors\Infrastructure\Notifiers\MailNotifier;
use Plugins\Monitors\Infrastructure\Notifiers\WebhookNotifier;
use Plugins\Monitors\Infrastructure\Persistence\MonitorRepository;
use Plugins\Usage\API\Contracts\UsageContract;

/**
 * Monitors — wires the 'monitor.alerting' domain.
 *
 * Requires usage.metering (the numbers), webhook.delivery (the webhook channel
 * is an event that plugin delivers) and mail.delivery (the mail channel).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'monitor.alerting';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['usage.metering', 'webhook.delivery', 'mail.delivery'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [MonitorContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(MonitorConfig::class, static fn (): MonitorConfig => MonitorConfig::fromEnv());

        // Internal and shared: ONE DatabasePort per request, because the Database
        // plugin's port is transient and commit()'s transaction must stay on a
        // single connection.
        $container->bindInternal(
            MonitorStore::class,
            static fn (ModuleContainer $c): MonitorStore => new MonitorRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            MetricSource::class,
            static fn (ModuleContainer $c): MetricSource => new UsageMetricSource($c->make(UsageContract::class)),
        );

        $container->bind(
            MonitorContract::class,
            static function (ModuleContainer $c): MonitorService {
                $logger = $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null;

                $notifiers = [
                    'console' => new ConsoleNotifier($logger),
                    // The EventBus resolved HERE is the request-scoped view, which
                    // is what lets the Webhooks listener find its own bindings.
                    'webhook' => new WebhookNotifier($c->make(EventBus::class)),
                ];

                if ($c->has(MailerContract::class)) {
                    $notifiers['mail'] = new MailNotifier($c->make(MailerContract::class));
                }

                return new MonitorService(
                    store:     $c->make(MonitorStore::class),
                    metrics:   $c->make(MetricSource::class),
                    clock:     $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                    config:    $c->make(MonitorConfig::class),
                    notifiers: $notifiers,
                    cache:     $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                    logger:    $logger,
                );
            },
        );

        // Declared in module.json `jobs[]` as "monitors.evaluate". PUBLIC: the
        // worker resolves a job handler from outside the module's scope, where an
        // internal binding throws ScopeViolationException (see the same note in
        // the Usage plugin's Provider).
        $container->bind(
            EvaluateMonitorsJob::class,
            static fn (ModuleContainer $c): EvaluateMonitorsJob => new EvaluateMonitorsJob(
                $c->make(MonitorContract::class),
                $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // module.json `jobs[]` only compiles job-manifest.php. The worker's
        // NAME -> CLASS registry is this call; without it a queued
        // "monitors.evaluate" is reported as an unknown job and ACKED, so the
        // schedule would queue evaluations that nothing ever runs.
        $worker->job('monitors.evaluate', EvaluateMonitorsJob::class);

        $cli->defer(static function (CliPipeline $cli): void {
            $core   = $cli->container();
            $config = MonitorConfig::fromEnv();
            $queue  = $core->has(QueuePort::class) ? $core->make(QueuePort::class) : null;

            // Rule management and status need only the database. Reading metrics
            // does not work here (see WorkerOnlyMetricSource), so the CLI never
            // evaluates in-process: monitors:evaluate queues the job instead.
            $service = self::forCli($core, $config);

            $cli->command(new MonitorsEvaluateCommand($queue, trim((string) env('MONITORS_QUEUE')) ?: 'monitors'));
            $cli->command(new MonitorsStatusCommand($service));
            $cli->command(new MonitorsAddCommand($service));
            $cli->command(new MonitorsPruneCommand($service));
        });
    }

    private static function forCli(CoreContainer $core, MonitorConfig $config): MonitorService
    {
        return new MonitorService(
            store:   new MonitorRepository($core->make(DatabasePort::class)),
            metrics: new WorkerOnlyMetricSource(),
            clock:   $core->has(ClockPort::class) ? $core->make(ClockPort::class) : new SystemClock(),
            config:  $config,
            cache:   $core->has(CachePort::class) ? $core->make(CachePort::class) : null,
            logger:  $core->has(LoggerPort::class) ? $core->make(LoggerPort::class) : null,
        );
    }
}
