<?php

declare(strict_types=1);

namespace Plugins\Monitors\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Published when a rule changes state. Primitives only, so a consumer needs none
 * of this plugin's types.
 *
 * `alertId` is stable across delivery retries: a receiver that sees the same
 * alert twice (the channel is at-least-once) can drop the duplicate on it.
 */
final readonly class MonitorAlertIntegrationEvent implements IntegrationEventContract
{
    public function __construct(
        public string $alertId,
        public string $kind,        // 'fired' | 'resolved'
        public string $rule,
        public string $metric,
        public string $severity,    // 'info' | 'warning' | 'critical'
        public float $value,
        public float $threshold,
        public string $tenant,
        public string $occurredAt,  // RFC3339
    ) {}

    public function name(): string
    {
        return 'monitor.alert.' . $this->kind;
    }

    public function version(): string
    {
        return '1.0';
    }

    /** @return array<string, string|float> */
    public function payload(): array
    {
        return [
            'alert_id'    => $this->alertId,
            'kind'        => $this->kind,
            'rule'        => $this->rule,
            'metric'      => $this->metric,
            'severity'    => $this->severity,
            'value'       => $this->value,
            'threshold'   => $this->threshold,
            'tenant'      => $this->tenant,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
