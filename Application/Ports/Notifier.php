<?php

declare(strict_types=1);

namespace Plugins\Monitors\Application\Ports;

use Plugins\Monitors\Domain\Alert;

interface Notifier
{
    /** 'mail' | 'webhook' | 'console' */
    public function channel(): string;

    /** @throws \Throwable on any failure: the channel is retried, the others are not repeated */
    public function send(Alert $alert): void;
}
