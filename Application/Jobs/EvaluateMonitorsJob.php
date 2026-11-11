<?php

declare(strict_types=1);

namespace Plugins\Monitors\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Monitors\API\Contracts\MonitorContract;

/**
 * What the `monitors.evaluate` schedule entry queues. A job rather than a
 * `command`: it runs in a WorkerLoop container with this module's `requires`
 * (usage, webhooks, mail) registered, which a command run from the scheduler
 * process would not have — and the Webhooks listener can only be built there.
 *
 * Declared with retry max 1: the schedule re-queues every few minutes, and a
 * retry of a run that failed half way is a second evaluator.
 */
final class EvaluateMonitorsJob implements JobContract
{
    public function __construct(
        private readonly MonitorContract $monitors,
        private readonly ?LoggerPort $logger = null,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $report = $this->monitors->evaluate();

        if ($report->locked) {
            return JobResult::skipped('Another evaluation holds the lock.');
        }

        return JobResult::success([
            'evaluated' => $report->evaluated,
            'fired'     => \count($report->fired),
            'resolved'  => \count($report->resolved),
            'failed'    => \count($report->failed),
            'delivered' => $report->delivered,
        ]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        $this->logger?->error('Monitor evaluation failed: {message}', ['message' => $e->getMessage()]);
    }
}
