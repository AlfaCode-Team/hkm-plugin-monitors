<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Monitors\API\Contracts\MonitorContract;

/** monitors:prune — delete settled alerts past retention. Undelivered ones are never deleted. */
final class MonitorsPruneCommand extends AbstractCommand
{
    public function __construct(private readonly MonitorContract $monitors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'monitors:prune';
        $this->description = 'Delete settled monitor alerts past the retention window';

        $this->addOption('quiet-when-idle', '', 'Print nothing when nothing was deleted');
    }

    protected function handle(): int
    {
        $n = $this->monitors->prune();

        if ($n > 0) {
            $this->success("Pruned {$n} alert(s).");
        } elseif (!$this->hasOption('quiet-when-idle')) {
            $this->muted('Nothing to prune.');
        }

        return self::SUCCESS;
    }
}
