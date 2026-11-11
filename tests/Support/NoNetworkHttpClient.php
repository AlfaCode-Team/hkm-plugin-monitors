<?php

declare(strict_types=1);

namespace Tests\Plugins\Monitors\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/** The Webhooks plugin needs an HttpClientPort to be CONSTRUCTED; queuing a delivery never calls it. Any call is a test failure. */
final class NoNetworkHttpClient implements HttpClientPort
{
    private function refuse(): never
    {
        throw new \LogicException('a test made an outbound HTTP request');
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse { $this->refuse(); }
    public function get(string $url, array $query = []): HttpClientResponse { $this->refuse(); }
    public function post(string $url, array $data = []): HttpClientResponse { $this->refuse(); }
    public function put(string $url, array $data = []): HttpClientResponse { $this->refuse(); }
    public function patch(string $url, array $data = []): HttpClientResponse { $this->refuse(); }
    public function delete(string $url, array $data = []): HttpClientResponse { $this->refuse(); }
    public function pending(): PendingRequestContract { $this->refuse(); }
}
