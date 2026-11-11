<?php

declare(strict_types=1);

namespace Plugins\Monitors\Infrastructure\Notifiers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Plugins\Monitors\API\IntegrationEvents\MonitorAlertIntegrationEvent;
use Plugins\Monitors\Application\Ports\Notifier;
use Plugins\Monitors\Domain\Alert;

/**
 * Dispatches `monitor.alert.fired|resolved` on the EventBus, which is how the
 * Webhooks plugin hears about anything.
 *
 * `EventBus::dispatch()` ISOLATES listener failures and RETURNS them rather than
 * throwing. Ignoring that return value would mark the alert delivered when the
 * Webhooks listener had just failed to queue it — so the result is checked, and
 * any failure makes this channel fail and the alert retry.
 */
final class WebhookNotifier implements Notifier
{
    public function __construct(private readonly EventBus $bus) {}

    public function channel(): string
    {
        return 'webhook';
    }

    public function send(Alert $alert): void
    {
        $failures = $this->bus->dispatch(new MonitorAlertIntegrationEvent(
            alertId:    $alert->id,
            kind:       $alert->kind->value,
            rule:       $alert->ruleName,
            metric:     $alert->metric,
            severity:   $alert->severity->value,
            value:      $alert->value,
            threshold:  $alert->threshold,
            tenant:     $alert->tenant ?? '',
            occurredAt: gmdate(\DateTimeInterface::RFC3339, $alert->occurredAt),
        ));

        if ($failures !== []) {
            $first = reset($failures);

            throw new \RuntimeException(\count($failures) . ' listener(s) failed: ' . $first->getMessage(), previous: $first);
        }
    }
}
