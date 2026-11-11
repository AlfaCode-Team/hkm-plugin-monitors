<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use Plugins\Monitors\Application\Ports\Notifier;
use Plugins\Monitors\Domain\Alert;

final class RecordingNotifier implements Notifier
{
    /** @var list<Alert> */
    public array $sent = [];

    public int $failuresLeft = 0;

    /** @var (callable(Alert): void)|null runs at send time, before recording */
    public $onSend = null;

    public function __construct(private readonly string $channel) {}

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(Alert $alert): void
    {
        if ($this->onSend !== null) {
            ($this->onSend)($alert);
        }

        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;

            throw new \RuntimeException("{$this->channel} is down");
        }

        $this->sent[] = $alert;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_map(static fn (Alert $a): string => $a->kind->value, $this->sent);
    }
}
