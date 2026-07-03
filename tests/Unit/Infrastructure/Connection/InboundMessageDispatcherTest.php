<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\InboundMessageDispatcher;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Tests\Support\RecordingRelayMessageHandler;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InboundMessageDispatcherTest extends TestCase
{
    public function testRoutesAMessageToTheHandlerRegisteredForItsType(): void
    {
        $message = new NoticeMessage('hello');
        $handler = new RecordingRelayMessageHandler(RelayMessageType::Notice);
        $dispatcher = new InboundMessageDispatcher($this->deserialiserReturning($message), new NullLogger(), $handler);

        $dispatcher->dispatch($this->session(), 'raw');

        self::assertSame($message, $handler->received);
    }

    public function testDoesNotRouteWhenNoHandlerIsRegisteredForTheType(): void
    {
        $handler = new RecordingRelayMessageHandler(RelayMessageType::Eose);
        $dispatcher = new InboundMessageDispatcher($this->deserialiserReturning(new NoticeMessage('hello')), new NullLogger(), $handler);

        $dispatcher->dispatch($this->session(), 'raw');

        self::assertNull($handler->received);
    }

    public function testDoesNotRouteAnUnparseableMessage(): void
    {
        $handler = new RecordingRelayMessageHandler(RelayMessageType::Notice);
        $dispatcher = new InboundMessageDispatcher($this->deserialiserReturning(null), new NullLogger(), $handler);

        $dispatcher->dispatch($this->session(), 'raw');

        self::assertNull($handler->received);
    }

    private function deserialiserReturning(?RelayMessage $message): MessageDeserialiserInterface
    {
        $deserialiser = $this->createStub(MessageDeserialiserInterface::class);
        $deserialiser->method('deserialiseRelayMessage')->willReturn($message);

        return $deserialiser;
    }

    private function session(): RelaySession
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test') ?? self::fail('invalid relay URL');
        $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        return new RelaySession($connection, new ScriptedWebsocketConnection());
    }
}
