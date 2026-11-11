<?php

declare(strict_types=1);

namespace Plugins\Monitors\Domain;

/**
 * A transition as it was recorded, with the routing it had AT THAT MOMENT.
 *
 * The channels are a snapshot. Editing a rule while an alert is waiting for a
 * retry must not redirect a page already in flight to somebody else, and a rule
 * deleted in the meantime must not strand its pending alert with nowhere to go.
 */
final readonly class Alert
{
    /**
     * @param list<string> $mailTo
     * @param list<string> $delivered channels already done — retries skip them,
     *                                so a flaky webhook does not re-send the mail
     */
    public function __construct(
        public string $id,
        public string $ruleId,
        public string $ruleName,
        public string $metric,
        public Severity $severity,
        public AlertKind $kind,
        public float $value,
        public float $threshold,
        public int $occurredAt,
        public ?string $tenant,
        public array $mailTo,
        public bool $webhook,
        public bool $console,
        public array $delivered = [],
        public int $attempts = 0,
    ) {}

    /** @return list<string> */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->mailTo !== [] ? 'mail' : null,
            $this->webhook ? 'webhook' : null,
            $this->console ? 'console' : null,
        ]));
    }

    /** @return list<string> */
    public function remaining(): array
    {
        return array_values(array_diff($this->channels(), $this->delivered));
    }
}
