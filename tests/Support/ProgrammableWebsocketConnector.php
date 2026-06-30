<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use LogicException;
use Override;
use Throwable;

/**
 * Hands out a scripted result per connect() call: a connection to succeed, or a
 * throwable to fail, so a reconnect loop can be driven through failures to success.
 */
final class ProgrammableWebsocketConnector implements WebsocketConnector
{
    /** @var list<WebsocketConnection|Throwable> */
    private array $results;

    public int $connectCount = 0;

    /**
     * @param list<WebsocketConnection|Throwable> $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
    }

    #[Override]
    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        ++$this->connectCount;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        if ($result instanceof WebsocketConnection) {
            return $result;
        }

        throw new LogicException('ProgrammableWebsocketConnector exhausted');
    }
}
