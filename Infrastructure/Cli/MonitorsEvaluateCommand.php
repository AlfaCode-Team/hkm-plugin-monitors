<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * monitors:evaluate — queue an evaluation now, instead of waiting for the schedule.
 *
 * It queues rather than evaluating in-process because the work needs the
 * worker's container (the Usage contract, and the Webhooks listener) and
 * because queueing the SAME job the schedule queues means this path is covered
 * by the same overlap guards. It prints that it queued, not that it evaluated.
 */
final class MonitorsEvaluateCommand extends AbstractCommand
{
    public const JOB = 'monitors.evaluate';

    public function __construct(
        private readonly ?QueuePort $queue,
        private readonly string $queueName = 'monitors',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'monitors:evaluate';
        $this->description = 'Queue an evaluation of every enabled monitor rule';
    }

    protected function handle(): int
    {
        if ($this->queue === null) {
            $this->error('No QueuePort is bound, so there is nothing to run the evaluation on.');

            return self::FAILURE;
        }

        $id = $this->queue->push(self::JOB, [], $this->queueName);

        $this->success("Queued evaluation #{$id} on [{$this->queueName}]. A worker must be running it.");

        return self::SUCCESS;
    }
}
