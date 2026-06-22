<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Amp\Websocket\WebsocketMessage;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class AmphpRelayConnectionTest extends TestCase
{
    public function testRespondsToApplicationPingNoticeWithCloseForThrowawaySubscription(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        $websocket = new FakeWebsocketConnection(WebsocketMessage::fromText('["NOTICE","ping"]'));
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($websocket)),
            new JsonMessageDeserialiser(),
        );

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->expects(self::never())->method('handleNotice');

        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));

        $subscriptionId = SubscriptionId::fromString('sub-1');
        self::assertNotNull($subscriptionId);
        $filter = Filter::fromArray(['kinds' => [1]]);
        self::assertNotNull($filter);
        $connection->subscribe($relayUrl, $subscriptionId, $filter, $handler);

        delay(0.1);

        $keepAlive = SubscriptionId::fromString('keepalive');
        self::assertNotNull($keepAlive);
        $expectedClose = new CloseMessage($keepAlive);
        self::assertContains($expectedClose->toJson(), $websocket->sentTexts);
    }
}
