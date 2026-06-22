<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Override;

final readonly class FakeWebsocketConnector implements WebsocketConnector
{
    public function __construct(private WebsocketConnection $connection)
    {
    }

    #[Override]
    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        return $this->connection;
    }
}
