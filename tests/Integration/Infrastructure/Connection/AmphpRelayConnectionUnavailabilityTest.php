<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Error;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\ProgrammableWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\RecordingEventHandler;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\delay;

final class AmphpRelayConnectionUnavailabilityTest extends TestCase
{
    public function testConnectingToAnUnreachableRelayReturnsFailedToConnect(): void
    {
        $connection = new AmphpRelayConnection(new ConnectionFactory(new ProgrammableWebsocketConnector([new RuntimeException('connection refused')])));

        $result = $connection->connect($this->relayUrl(), $this->config());

        self::assertFalse($result->isConnected());
        self::assertSame('failed to connect', $result->getMessage());
    }

    public function testAnUnreachableRelayIsLeftDisconnected(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = new AmphpRelayConnection(new ConnectionFactory(new ProgrammableWebsocketConnector([new RuntimeException('connection refused')])));

        $connection->connect($relayUrl, $this->config());

        self::assertFalse($connection->isConnected($relayUrl));
    }

    public function testConnectingToAReachableRelayReturnsConnected(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector(new ScriptedWebsocketConnection())));

        self::assertTrue($connection->connect($relayUrl, $this->config())->isConnected());

        $connection->disconnect($relayUrl);
    }

    public function testAFaultOfTheClientWhileConnectingIsThrown(): void
    {
        $connection = new AmphpRelayConnection(new ConnectionFactory(new ProgrammableWebsocketConnector([new Error('broken invariant')])));

        $this->expectException(Error::class);

        $connection->connect($this->relayUrl(), $this->config());
    }

    public function testSubscribingToARelayNeverConnectedClosesTheSubscriptionAsDisconnected(): void
    {
        $events = new RecordingEventHandler();
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector(new ScriptedWebsocketConnection())));

        $connection->subscribe(SubscriptionRequest::for($this->relayUrl(), Filter::from(), SubscriptionId::generate()), $events);

        self::assertSame(['disconnected'], $events->closedReasons);
    }

    public function testASubscriptionOpenWhenTheRelayDropsIsClosedAsDisconnected(): void
    {
        $relayUrl = $this->relayUrl();
        $events = new RecordingEventHandler();
        $ws = new ScriptedWebsocketConnection();
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector($ws)));
        $connection->connect($relayUrl, $this->config());
        delay(0.01);

        $connection->subscribe(SubscriptionRequest::for($relayUrl, Filter::from(), SubscriptionId::generate()), $events);
        delay(0.01);
        $ws->endStream();
        delay(0.01);

        self::assertSame(['disconnected'], $events->closedReasons);

        $connection->disconnect($relayUrl);
    }

    public function testPingingARelayNeverConnectedReportsItDisconnected(): void
    {
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector(new ScriptedWebsocketConnection())));

        $result = $connection->ping($this->relayUrl());

        self::assertFalse($result->isHealthy());
        self::assertSame('disconnected', $result->getErrorMessage());
    }

    public function testPingingALiveRelayReportsItHealthy(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector(new ScriptedWebsocketConnection())));
        $connection->connect($relayUrl, $this->config());
        delay(0.01);

        self::assertTrue($connection->ping($relayUrl)->isHealthy());

        $connection->disconnect($relayUrl);
    }

    private function config(): ConnectionConfig
    {
        return new ConnectionConfig(autoReconnect: false);
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }
}
