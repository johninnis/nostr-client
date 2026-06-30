<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Closure;
use IteratorAggregate;
use LogicException;
use Override;
use Traversable;

/**
 * A websocket whose inbound stream stays open until the test ends it, modelling a
 * live connection whose message-handler loop keeps running until the remote closes.
 *
 * @implements IteratorAggregate<int, WebsocketMessage>
 */
final class ControllableWebsocketConnection implements WebsocketConnection, IteratorAggregate
{
    /** @var list<string> */
    public array $sentTexts = [];

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $remoteClose;

    public function __construct()
    {
        $this->remoteClose = new DeferredFuture();
    }

    public function simulateRemoteClose(): void
    {
        if (!$this->remoteClose->isComplete()) {
            $this->remoteClose->complete(null);
        }
    }

    #[Override]
    public function getIterator(): Traversable
    {
        $this->remoteClose->getFuture()->await();

        yield from [];
    }

    #[Override]
    public function sendText(string $data): void
    {
        $this->sentTexts[] = $data;
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        throw new LogicException('ControllableWebsocketConnection delivers messages through iteration only');
    }

    #[Override]
    public function getId(): int
    {
        return 1;
    }

    #[Override]
    public function getLocalAddress(): SocketAddress
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function getRemoteAddress(): SocketAddress
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    #[Override]
    public function getCloseInfo(): WebsocketCloseInfo
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function isCompressionEnabled(): bool
    {
        return false;
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function streamText(ReadableStream $stream): void
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function streamBinary(ReadableStream $stream): void
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function ping(): void
    {
        throw new LogicException('not used in test');
    }

    #[Override]
    public function getCount(WebsocketCount $type): int
    {
        return 0;
    }

    #[Override]
    public function getTimestamp(WebsocketTimestamp $type): float
    {
        return 0.0;
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->remoteClose->isComplete();
    }

    #[Override]
    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
    }

    #[Override]
    public function onClose(Closure $onClose): void
    {
    }

    #[Override]
    public function getHandshakeResponse(): Response
    {
        throw new LogicException('not used in test');
    }
}
