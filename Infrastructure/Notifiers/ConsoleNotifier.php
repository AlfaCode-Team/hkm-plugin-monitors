<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Monitors\Application\Ports\Notifier;
use Plugins\Monitors\Domain\Alert;
use Plugins\Monitors\Domain\AlertKind;
use Plugins\Monitors\Domain\Severity;
use Plugins\Monitors\Infrastructure\Support\Messages;
use Plugins\Monitors\Infrastructure\Support\Number;

/**
 * The "console" channel: the application log, which is where a worker's console
 * output goes, plus (from `monitors:evaluate`) the terminal that ran it.
 */
final class ConsoleNotifier implements Notifier
{
    public function __construct(
        private readonly ?LoggerPort $logger,
        private readonly Messages $messages = new Messages(),
    ) {}

    public function channel(): string
    {
        return 'console';
    }

    public function send(Alert $alert): void
    {
        $line = $this->messages->get($alert->kind === AlertKind::Fired ? 'console.fired' : 'console.resolved', [
            'rule'      => $alert->ruleName,
            'metric'    => $alert->metric,
            'value'     => Number::format($alert->value),
            'threshold' => Number::format($alert->threshold),
            'severity'  => $alert->severity->value,
        ]);

        // A resolution is good news and is logged as such, whatever the rule's severity.
        $level = $alert->kind === AlertKind::Resolved ? 'info' : match ($alert->severity) {
            Severity::Critical => 'critical',
            Severity::Warning  => 'warning',
            Severity::Info     => 'info',
        };

        $this->logger?->log($level, $line, ['alert' => $alert->id, 'rule' => $alert->ruleName]);
    }
}
