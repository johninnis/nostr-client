<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Closure;
use Override;

/**
 * Produces a fresh connection from a factory on every connect(), so a fuzz run can
 * drive an unbounded number of reconnects without pre-scripting each socket.
 */
final readonly class SuppliedWebsocketConnector implements WebsocketConnector
{
    /** @var Closure(): WebsocketConnection */
    private Closure $factory;

    /**
     * @param Closure(): WebsocketConnection $factory
     */
    public function __construct(Closure $factory)
    {
        $this->factory = $factory;
    }

    #[Override]
    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        return ($this->factory)();
    }
}
