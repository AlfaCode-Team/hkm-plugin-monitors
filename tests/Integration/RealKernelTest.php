<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Integration;

use AlfacodeTeam\Ground\BootedGround;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use PHPUnit\Framework\TestCase;
use Plugins\HttpClient\Provider as HttpClientProvider;
use Plugins\Mail\Provider as MailProvider;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Provider;
use Plugins\Usage\Provider as UsageProvider;
use Plugins\Webhooks\API\Contracts\WebhookContract;
use Plugins\Webhooks\Provider as WebhooksProvider;
use Tests\Plugins\Monitors\Support\NoNetworkHttpClient;

/**
 * The whole chain through the REAL kernel (Ground boots it): real BootPipeline
 * compiling all five modules' module.json, real HttpPipeline with the Usage
 * after.execute hook, real WorkerLoop running both jobs, real CLI pipeline
 * running the commands, and the real Webhooks and Mail plugins on the other
 * end.
 *
 *   3 HTTP requests -> usage.flush queued -> worker applies -> `monitors:add`
 *   -> `monitors:evaluate` queues the job -> worker evaluates -> rule fires ->
 *   mail (array transport) + monitor.alert.fired -> Webhooks queues a delivery.
 *
 * Not covered: a real SMTP server, a real HTTP POST to the webhook receiver
 * (the delivery is queued, never sent), MySQL/PostgreSQL, and PHP-FPM/OpenSwoole.
 */
final class RealKernelTest extends TestCase
{
    private ?MigratedSqlite $db = null;
    private ?BootedGround $ground = null;
    private string $migrations = '';

    protected function setUp(): void
    {
        $root = \dirname(__DIR__, 3);

        // One database holding all three plugins' tables.
        $this->migrations = sys_get_temp_dir() . '/monitors-e2e-' . bin2hex(random_bytes(6));
        mkdir($this->migrations);
        foreach (['hkm-plugin-usage', 'hkm-plugin-monitors', 'hkm-plugin-webhooks'] as $plugin) {
            foreach (glob("{$root}/{$plugin}/database/migrations/*.php") ?: [] as $file) {
                copy($file, $this->migrations . '/' . basename($file));
            }
        }

        $this->db = MigratedSqlite::migrated($this->migrations, 'monitors-e2e');

        $this->ground = PluginGround::for(Provider::class, UsageProvider::class, WebhooksProvider::class, MailProvider::class, HttpClientProvider::class)
            ->port(DatabasePort::class, $this->db)
            ->port(HttpClientPort::class, new NoNetworkHttpClient())
            ->env(['MAIL_TRANSPORT' => 'array', 'MAIL_FROM_ADDRESS' => 'alerts@example.com'])
            ->routes([['method' => 'GET', 'path' => '/probe', 'handler' => 'Tests\\Plugins\\Monitors\\Support\\ProbeController@ok']])
            ->boot();
    }

    protected function tearDown(): void
    {
        $this->ground?->destroy();
        $this->db?->discard();

        foreach (glob($this->migrations . '/*') ?: [] as $file) {
            unlink($file);
        }
        if ($this->migrations !== '' && is_dir($this->migrations)) {
            rmdir($this->migrations);
        }
    }

    private function g(): BootedGround
    {
        return $this->ground ?? throw new \LogicException('not booted');
    }

    private function db(): MigratedSqlite
    {
        return $this->db ?? throw new \LogicException('no database');
    }

    public function test_traffic_becomes_usage_becomes_a_fired_alert_delivered_on_every_channel_exactly_once(): void
    {
        // An application registers a webhook for the alerts.
        $this->g()->service(WebhookContract::class)->register('https://hooks.example.com/alerts', ['monitor.alert.*']);

        // An operator defines the rule from the CLI (the real command, through the real CLI pipeline).
        $added = $this->g()->cli('monitors:add', [
            'traffic.high', '--metric=http.requests', '--fire=2', '--resolve=1', '--mail=ops@example.com', '--severity=critical',
        ]);
        $this->assertSame(0, $added->exitCode, $added->output());

        // Three requests through the real pipeline. Nothing is written yet.
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->g()->get('/probe')->status());
        }
        $this->assertSame(0, (int) $this->db()->queryOne('SELECT COUNT(*) AS n FROM usage_buckets')['n'], 'the request path wrote to the database');

        // The worker applies the three batches.
        $this->g()->work('usage', 3);
        $this->assertSame(3, (int) $this->db()->queryOne("SELECT counter_sum FROM usage_buckets WHERE metric = 'http.requests'")['counter_sum']);

        // Queue an evaluation and let the worker run it.
        $queued = $this->g()->cli('monitors:evaluate');
        $this->assertSame(0, $queued->exitCode, $queued->output());
        $this->g()->work('monitors', 1);

        $alerts = $this->db()->query('SELECT * FROM monitor_alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame('fired', $alerts[0]['kind']);

        $delivered = json_decode($alerts[0]['delivered'], true);
        $this->assertContains('mail', $delivered, 'mail through the real Mail plugin (array transport) failed');
        $this->assertContains('console', $delivered);

        // A second evaluation with the metric unchanged raises nothing more.
        $this->g()->cli('monitors:evaluate');
        $this->g()->work('monitors', 1);
        $this->assertSame(1, (int) $this->db()->queryOne('SELECT COUNT(*) AS n FROM monitor_alerts')['n'], 'a second alert was raised for an unchanged metric');

        $status = $this->g()->cli('monitors:status');
        $this->assertSame(1, $status->exitCode, 'a firing rule makes the status command fail');
        $this->assertTrue($status->sees('traffic.high'));
        $this->assertFalse($status->sees('ops@example.com'));

        // THE WEBHOOK CHANNEL. Measured against hkm-plugin-webhooks v0.1.0: its
        // EnqueueWebhookListener cannot be built by the kernel, because
        // WebhookService is bindInternal() and the EventBus resolves the listener
        // from OUTSIDE that module's scope (ScopeViolationException). Our channel
        // reports it, keeps the alert pending and retries — which is the design
        // working — but nothing reaches an endpoint. This is not asserted as
        // failing (the day Webhooks fixes it this test must go green by itself).
        if (!\in_array('webhook', $delivered, true)) {
            $this->assertNull($alerts[0]['notified_at'], 'an alert with an undelivered channel must stay pending');
            $this->assertSame(1, $this->g()->service(MonitorContract::class)->backlog()['pending']);
            $this->markTestIncomplete(
                'webhook channel: the Webhooks plugin could not queue the delivery (see the report: its listener '
                . 'depends on an internal binding the EventBus cannot resolve). Everything else in the chain passed.'
            );
        }

        $this->assertSame(
            ['monitor.alert.fired'],
            array_column($this->db()->query('SELECT event_name FROM webhook_deliveries'), 'event_name'),
        );
        $this->assertNotNull($alerts[0]['notified_at']);
    }

    public function test_the_schedule_is_compiled_by_the_real_kernel_with_overlap_protection(): void
    {
        // `schedule:list` prints its table straight to stdout, which BufferIO does
        // not capture, so read the manifest the real BootPipeline wrote.
        $this->g()->commandNames(); // forces activation

        $tasks = \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader::readCompiled('schedule-manifest.php');

        $this->assertTrue($tasks['monitors.evaluate']['withoutOverlapping']);
        $this->assertSame('monitors.evaluate', $tasks['monitors.evaluate']['target']);
        $this->assertTrue($tasks['usage.rollup']['withoutOverlapping']);
    }

    public function test_the_contract_resolves_with_all_three_required_modules_present(): void
    {
        $this->assertStringContainsString(
            'channels [console, webhook, mail]',
            $this->g()->service(MonitorContract::class)->describe(),
        );
    }
}
