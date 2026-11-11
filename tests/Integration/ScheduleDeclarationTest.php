<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Integration;

use AlfacodeTeam\Ground\Fakes\FakeCache;
use AlfacodeTeam\Ground\Fakes\FakeQueue;
use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileScheduleManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\Scheduler;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\TestCase;
use Plugins\Monitors\Provider;

/**
 * "withoutOverlapping is mandatory" is pinned against the KERNEL'S OWN compiler
 * and scheduler, not against a reading of the JSON.
 *
 * module.json is compiled by the real CompileScheduleManifestStage into a real
 * schedule-manifest.php, and the real Scheduler is ticked twice. Without the
 * flag the second tick queues a second evaluation and both would raise the same
 * alert.
 *
 * NOT covered, and stated rather than implied: FakeCache's locks do not EXPIRE,
 * so "the lock lapses after expiresAfter" is asserted as a declared number
 * (240 < the 300s interval), not by watching it lapse.
 */
final class ScheduleDeclarationTest extends TestCase
{
    private string $tmp = '';
    private string $previousBase = '';
    private bool $ready = false;

    protected function setUp(): void
    {
        $this->previousBase = Paths::base();
        $this->tmp          = sys_get_temp_dir() . '/monitors-sched-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);

        Paths::setBase($this->tmp);
        Paths::setProject(null);
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if (!$this->ready) {
            return;
        }

        Paths::setBase($this->previousBase);

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmp);
    }

    /** @return array<string, mixed> */
    private function compiled(): array
    {
        (new CompileScheduleManifestStage([Provider::class]))->run();

        return ManifestReader::readCompiled('schedule-manifest.php');
    }

    public function test_the_evaluation_task_is_declared_without_overlapping_and_compiles(): void
    {
        $task = $this->compiled()['monitors.evaluate'] ?? null;

        $this->assertNotNull($task, 'the kernel compiler rejected or dropped the schedule entry');
        $this->assertTrue($task['withoutOverlapping'], 'WITHOUT THIS A SLOW EVALUATION DOUBLE-FIRES EVERY ALERT');
        $this->assertSame('monitors.evaluate', $task['target']);
        $this->assertSame('monitors', $task['queue']);
    }

    public function test_the_lock_lifetime_is_shorter_than_the_interval_so_a_finished_run_does_not_block_the_next(): void
    {
        $task = $this->compiled()['monitors.evaluate'];

        // "*/5": five minutes. A queued job's lock is not released on completion;
        // it only expires, so it must lapse before the next tick.
        $this->assertGreaterThanOrEqual(60, $task['expiresAfter']);
        $this->assertLessThan(300, $task['expiresAfter']);
    }

    public function test_the_real_scheduler_refuses_a_second_dispatch_while_the_first_is_outstanding(): void
    {
        $this->compiled();

        $clock     = new FrozenClock('2026-03-05 12:00:00');
        $queue     = new FakeQueue();
        $scheduler = new Scheduler($queue, new FakeCache($clock), null, $clock);

        $first  = $scheduler->runTask('monitors.evaluate');
        $second = $scheduler->runTask('monitors.evaluate'); // a slow run, or a second app server

        $this->assertSame('queued', $first['outcome']);
        $this->assertSame('skipped', $second['outcome']);
        $this->assertStringContainsString('lock', $second['detail']);
        $this->assertSame(1, $queue->pushCount('monitors.evaluate'), 'exactly ONE evaluation was queued');
    }

    public function test_the_same_ticks_through_due_dispatch_once_at_a_five_minute_boundary(): void
    {
        $this->compiled();

        $clock     = new FrozenClock('2026-03-05 12:05:00');
        $queue     = new FakeQueue();
        $scheduler = new Scheduler($queue, new FakeCache($clock), null, $clock);

        $scheduler->run();
        $scheduler->run(); // two schedulers ticking the same minute

        $this->assertSame(1, $queue->pushCount('monitors.evaluate'));
    }

    public function test_without_a_cache_the_guarded_task_is_skipped_rather_than_run_unguarded(): void
    {
        $this->compiled();

        $clock = new FrozenClock('2026-03-05 12:05:00');
        $queue = new FakeQueue();

        $result = (new Scheduler($queue, null, null, $clock))->runTask('monitors.evaluate');

        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame(0, $queue->pushCount('monitors.evaluate'));
    }

    public function test_every_scheduled_job_is_a_declared_job_with_an_existing_handler(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $jobs     = array_column($manifest['jobs'], null, 'name');

        foreach ($manifest['schedule'] as $entry) {
            $this->assertTrue($entry['withoutOverlapping'] ?? false, "schedule [{$entry['name']}] must not overlap");

            if (isset($entry['job'])) {
                $this->assertArrayHasKey($entry['job'], $jobs, 'a scheduled job nobody declared would be queued and never run');
                $this->assertTrue(is_subclass_of($jobs[$entry['job']]['handler'], JobContract::class));
            }
        }
    }

    public function test_the_manifest_requires_exactly_the_three_domains_and_a_kernel_floor(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['usage.metering', 'webhook.delivery', 'mail.delivery'], $manifest['requires']);
        $this->assertSame($manifest['requires'], (new Provider())->requires(), 'Provider::requires() must mirror module.json');
        $this->assertNotEmpty($manifest['kernel']);
        $this->assertSame('monitor.alerting', $manifest['solves']);
        $this->assertSame([], $manifest['routes']);
    }
}
