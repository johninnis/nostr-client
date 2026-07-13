<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AuthMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\ClosedMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\EoseMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\EventMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\InboundMessageDispatcher;
use Innis\Nostr\Client\Infrastructure\Connection\NoticeMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\OkMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InboundMessageDispatcherTest extends TestCase
{
    public function testRoutesAMessageToTheHandlerForItsType(): void
    {
        $ws = new ScriptedWebsocketConnection();
        $dispatcher = $this->dispatcher($this->deserialiserReturning(new NoticeMessage('ping')));

        $dispatcher->dispatch($this->session($ws), 'raw');

        self::assertSame(['["CLOSE","keepalive"]'], $ws->sentTexts);
    }

    public function testIgnoresAnUnparseableMessage(): void
    {
        $ws = new ScriptedWebsocketConnection();
        $dispatcher = $this->dispatcher($this->deserialiserReturning(null));

        $dispatcher->dispatch($this->session($ws), 'raw');

        self::assertSame([], $ws->sentTexts);
    }

    private function dispatcher(MessageDeserialiserInterface $deserialiser): InboundMessageDispatcher
    {
        return new InboundMessageDispatcher(
            $deserialiser,
            new NullLogger(),
            new EventMessageHandler(),
            new OkMessageHandler(),
            new EoseMessageHandler(),
            new ClosedMessageHandler(),
            new NoticeMessageHandler(new NullLogger()),
            new AuthMessageHandler(new NullLogger()),
        );
    }

    private function deserialiserReturning(?RelayMessage $message): MessageDeserialiserInterface
    {
        $deserialiser = $this->createStub(MessageDeserialiserInterface::class);
        $deserialiser->method('deserialiseRelayMessage')->willReturn($message);

        return $deserialiser;
    }

    private function session(ScriptedWebsocketConnection $ws): RelaySession
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.test') ?? self::fail('invalid relay URL');
        $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        return new RelaySession($connection, $ws);
    }
}
