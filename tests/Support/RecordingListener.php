<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/** A stand-in for the Webhooks wildcard listener. Static ONLY because it is a test double built by a container. */
final class RecordingListener implements EventListenerContract
{
    /** @var list<IntegrationEventContract> */
    public static array $events = [];

    public static bool $throw = false;

    public function handle(IntegrationEventContract $event): void
    {
        if (self::$throw) {
            throw new \RuntimeException('could not queue deliveries');
        }

        self::$events[] = $event;
    }
}
