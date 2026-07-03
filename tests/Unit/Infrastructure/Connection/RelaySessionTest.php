<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\TestCase;

final class RelaySessionTest extends TestCase
{
    public function testSendWritesTheMessageJsonToTheSocket(): void
    {
        $ws = new ScriptedWebsocketConnection();
        $session = $this->session($ws);
        $message = new CloseMessage($this->subscriptionId());

        $session->send($message);

        self::assertSame([$message->toJson()], $ws->sentTexts);
    }

    public function testSendThrowsWhenTheSocketHasBeenLost(): void
    {
        $session = $this->session(new ScriptedWebsocketConnection());
        $session->loseWebsocket();

        $this->expectException(ConnectionException::class);

        $session->send(new CloseMessage($this->subscriptionId()));
    }

    private function session(ScriptedWebsocketConnection $ws): RelaySession
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test') ?? self::fail('invalid relay URL');
        $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        return new RelaySession($connection, $ws);
    }

    private function subscriptionId(): SubscriptionId
    {
        return SubscriptionId::tryFromString('sub-1') ?? self::fail('invalid subscription id');
    }
}
