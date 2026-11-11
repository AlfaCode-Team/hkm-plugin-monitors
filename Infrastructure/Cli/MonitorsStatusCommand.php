<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Infrastructure\Support\Number;

/**
 * monitors:status — every rule, where it is, and any alert not yet delivered.
 *
 * Exits non-zero when a rule is firing or an alert is undelivered, so it works
 * as a monitoring check without anything parsing its output.
 */
final class MonitorsStatusCommand extends AbstractCommand
{
    public function __construct(private readonly MonitorContract $monitors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'monitors:status';
        $this->description = 'Show monitor rules, their state and undelivered alerts';
    }

    protected function handle(): int
    {
        $this->section('Monitors');
        $this->muted($this->monitors->describe());
        $this->newLine();

        $states   = $this->monitors->states();
        $firing   = 0;

        foreach ($this->monitors->rules() as $rule) {
            $state  = $states[$rule->id] ?? null;
            $status = !$rule->enabled ? 'disabled' : ($state === null ? 'not evaluated' : $state->status);

            if ($state?->isFiring() && $rule->enabled) {
                $firing++;
            }

            $this->info(sprintf(
                '  %-28s %-14s %s %s fire %s / resolve %s   last %s',
                $rule->name,
                $status,
                $rule->metric,
                $rule->comparison->value,
                Number::format($rule->fireThreshold),
                Number::format($rule->resolveThreshold),
                $state?->lastValue === null ? '-' : Number::format($state->lastValue),
            ));

            if (!$rule->isDamped()) {
                $this->warning("    [{$rule->name}] has no hysteresis: it will flap whenever the metric sits at its threshold.");
            }
        }

        $backlog = $this->monitors->backlog();
        $this->newLine();
        $this->info("Alerts: {$backlog['pending']} waiting on a channel, {$backlog['abandoned']} given up on");

        if ($backlog['abandoned'] > 0) {
            $this->error('Alerts that were given up on were NEVER delivered on at least one channel.');
        }

        return $firing > 0 || $backlog['pending'] > 0 || $backlog['abandoned'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
