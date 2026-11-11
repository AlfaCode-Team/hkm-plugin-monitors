<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\Monitors\API\Contracts\MonitorContract;
use Plugins\Monitors\Domain\Comparison;
use Plugins\Monitors\Domain\Rule;
use Plugins\Monitors\Domain\Severity;
use Plugins\Monitors\Domain\Source;

/**
 * monitors:add — create a rule. The rule is validated by Rule::define(), the
 * same code an application uses, so the CLI cannot create one the contract
 * would refuse.
 */
final class MonitorsAddCommand extends AbstractCommand
{
    public function __construct(private readonly MonitorContract $monitors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'monitors:add';
        $this->description = 'Create a monitor rule over a usage metric';

        $this->addArgument('name', 'Rule name, e.g. api.errors', required: true);
        $this->addOption('metric', 'm', 'Usage metric, e.g. http.errors', acceptsValue: true);
        $this->addOption('source', 's', 'sum | gauge | growth (default sum)', acceptsValue: true);
        $this->addOption('op', 'o', 'gt | gte | lt | lte (default gt)', acceptsValue: true);
        $this->addOption('fire', 'f', 'Threshold that starts the alert', acceptsValue: true);
        $this->addOption('resolve', 'r', 'Threshold that ends it (default: same as fire)', acceptsValue: true);
        $this->addOption('window', 'w', 'Window in seconds, a multiple of 3600 (default 3600)', acceptsValue: true);
        $this->addOption('fire-after', '', 'Consecutive breaching evaluations before it fires (default 1)', acceptsValue: true);
        $this->addOption('resolve-after', '', 'Consecutive clear evaluations before it resolves (default 1)', acceptsValue: true);
        $this->addOption('tenant', 't', 'Only this tenant', acceptsValue: true);
        $this->addOption('severity', '', 'info | warning | critical (default warning)', acceptsValue: true);
        $this->addOption('mail', '', 'Comma-separated recipients', acceptsValue: true);
        $this->addOption('no-webhook', '', 'Do not publish monitor.alert.* events');
        $this->addOption('no-console', '', 'Do not log to the console channel');
    }

    protected function handle(): int
    {
        try {
            $mail = array_filter(array_map('trim', explode(',', (string) $this->option('mail', ''))));

            $rule = Rule::define(
                id:               Rule::newId(),
                name:             (string) $this->argument('name'),
                metric:           (string) $this->option('metric', ''),
                source:           Source::from((string) $this->option('source', 'sum')),
                comparison:       Comparison::from((string) $this->option('op', 'gt')),
                fireThreshold:    $this->number('fire'),
                resolveThreshold: $this->option('resolve') === null ? null : $this->number('resolve'),
                windowSeconds:    (int) $this->option('window', 3600),
                fireAfter:        (int) $this->option('fire-after', 1),
                resolveAfter:     (int) $this->option('resolve-after', 1),
                tenant:           $this->option('tenant') === null ? null : (string) $this->option('tenant'),
                severity:         Severity::from((string) $this->option('severity', 'warning')),
                mailTo:           array_values($mail),
                webhook:          !$this->hasOption('no-webhook'),
                console:          !$this->hasOption('no-console'),
            );

            $this->monitors->save($rule);
        } catch (\InvalidArgumentException | \ValueError | ServiceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->success("Rule [{$rule->name}] created (" . implode(', ', $rule->channels()) . ').');

        if (!$rule->isDamped()) {
            $this->warning('It has no hysteresis (same fire and resolve threshold, one evaluation each) and will flap at its threshold.');
        }

        return self::SUCCESS;
    }

    private function number(string $option): float
    {
        $value = $this->option($option);

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("--{$option} must be a number.");
        }

        return (float) $value;
    }
}
