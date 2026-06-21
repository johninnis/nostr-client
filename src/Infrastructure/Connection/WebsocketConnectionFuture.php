<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\Cancellation;
use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;

final readonly class WebsocketConnectionFuture
{
    public function __construct(private Future $future)
    {
    }

    public function await(?Cancellation $cancellation = null): WebsocketConnection
    {
        $connection = $this->future->await($cancellation);

        return $connection instanceof WebsocketConnection
            ? $connection
            : throw new ConnectionException('WebSocket connection future resolved to an unexpected type');
    }
}
