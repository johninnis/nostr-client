<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\Websocket\Client\WebsocketConnection;

final readonly class ActiveWebSocket
{
    public function __construct(
        private WebsocketConnection $connection,
    ) {
    }

    public function getConnection(): WebsocketConnection
    {
        return $this->connection;
    }
}
