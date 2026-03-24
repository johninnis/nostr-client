<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;

final readonly class ActiveWebSocket
{
    public function __construct(
        public WebsocketConnection $connection,
        public Future $messageHandlerTask,
    ) {
    }
}
