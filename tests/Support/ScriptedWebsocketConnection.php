<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\Response;
use Amp\Pipeline\Queue;
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
 * A websocket whose inbound frames the test pushes one at a time, so a multi-step
 * relay exchange (publish, OK, AUTH challenge, OK) can be driven deterministically.
 *
 * @implements IteratorAggregate<int, WebsocketMessage>
 */
final class ScriptedWebsocketConnection implements WebsocketConnection, IteratorAggregate
{
    /** @var list<string> */
    public array $sentTexts = [];

    /** @var Queue<WebsocketMessage> */
    private readonly Queue $inbound;

    public function __construct()
    {
        $this->inbound = new Queue();
    }

    public function pushInbound(string $json): void
    {
        $this->inbound->push(WebsocketMessage::fromText($json));
    }

    public function endStream(): void
    {
        if (!$this->inbound->isComplete()) {
            $this->inbound->complete();
        }
    }

    #[Override]
    public function getIterator(): Traversable
    {
        return $this->inbound->iterate();
    }

    #[Override]
    public function sendText(string $data): void
    {
        $this->sentTexts[] = $data;
    }

    #[Override]
    public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
    {
        throw new LogicException('ScriptedWebsocketConnection delivers messages through iteration only');
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
        return $this->inbound->isComplete();
    }

    #[Override]
    public function close(int $code = WebsocketCloseCode::NORMAL_CLOSE, string $reason = ''): void
    {
        $this->endStream();
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
