<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use LogicException;
use Override;

final class QueueWebsocketConnector implements WebsocketConnector
{
    /** @var list<WebsocketConnection> */
    private array $connections;

    /**
     * @param list<WebsocketConnection> $connections
     */
    public function __construct(array $connections)
    {
        $this->connections = $connections;
    }

    #[Override]
    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        $connection = array_shift($this->connections);

        if (null === $connection) {
            throw new LogicException('QueueWebsocketConnector exhausted');
        }

        return $connection;
    }
}
