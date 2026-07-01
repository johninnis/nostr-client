<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Amp\Future;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionManager;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Factory\EventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerTest extends TestCase
{
    private RelayUrl $relayUrl;
    /** @var array<string, RelayConnection> */
    private array $handlerConnections = [];

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
    }

    private function configureConnectionStateAccess(Stub $handler): void
    {
        $handler
            ->method('getConnection')
            ->willReturnCallback(fn (RelayUrl $url) => $this->handlerConnections[(string) $url] ?? null);

        $handler
            ->method('isConnected')
            ->willReturnCallback(fn (RelayUrl $url) => isset($this->handlerConnections[(string) $url]) && $this->handlerConnections[(string) $url]->isHealthy());

        $handler
            ->method('getAllConnections')
            ->willReturnCallback(fn () => new RelayConnectionCollection(array_values($this->handlerConnections)));
    }

    private function createHandlerStub(): ConnectionHandlerInterface&Stub
    {
        $handler = $this->createStub(ConnectionHandlerInterface::class);
        $this->configureConnectionStateAccess($handler);

        return $handler;
    }

    private function createHandlerMock(): ConnectionHandlerInterface&MockObject
    {
        $handler = $this->createMock(ConnectionHandlerInterface::class);
        $this->configureConnectionStateAccess($handler);

        return $handler;
    }

    private function establishConnection(?ConnectionConfig $config = null): RelayConnection
    {
        $config ??= new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $this->handlerConnections[(string) $this->relayUrl] = $connection;

        return $connection;
    }

    public function testConnectCreatesNewConnection(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->expects($this->once())
            ->method('connect')
            ->with($this->relayUrl, $config)
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $manager->connect($this->relayUrl, $config);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectWithDefaultConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        $handler
            ->method('connect')
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $manager->connect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectReturnsExistingHealthyConnection(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->expects($this->once())
            ->method('connect')
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $manager->connect($this->relayUrl, $config);
        $manager->connect($this->relayUrl, $config);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectThrowsOnFailure(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $exception = new ConnectionException('Connection failed');

        $handler
            ->expects($this->once())
            ->method('connect')
            ->willThrowException($exception);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection failed');

        $manager->connect($this->relayUrl, $config);
    }

    public function testConnectRecordsFailureWhenConnectionExists(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $exception = new ConnectionException('Connection failed');

        $connectCallCount = 0;
        $handler
            ->method('connect')
            ->willReturnCallback(function () use (&$connectCallCount, $connection, $exception): void {
                ++$connectCallCount;
                if (1 === $connectCallCount) {
                    $this->handlerConnections[(string) $this->relayUrl] = $connection;
                } else {
                    throw $exception;
                }
            });

        $manager->connect($this->relayUrl, $config);
        $this->handlerConnections[(string) $this->relayUrl] = $connection->withState(ConnectionState::FAILED);

        $this->expectException(ConnectionException::class);

        $manager->connect($this->relayUrl, $config);
    }

    public function testDisconnectRemovesConnection(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $handler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $manager->disconnect($this->relayUrl);

        $this->assertFalse($manager->isConnected($this->relayUrl));
        $this->assertNull($manager->getConnection($this->relayUrl));
    }

    public function testDisconnectUnsubscribesAll(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $subscriptionId = SubscriptionId::fromString('test-sub');
        self::assertNotNull($subscriptionId);

        $this->handlerConnections[(string) $this->relayUrl] = $connection;

        $handler
            ->method('subscribe')
            ->willReturnCallback(function () use ($subscriptionId): void {
                $url = (string) $this->relayUrl;
                $this->handlerConnections[$url] = $this->handlerConnections[$url]
                    ->withSubscription($subscriptionId, new FilterCollection([new Filter()]));
            });

        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $manager->subscribe($this->relayUrl, new Filter(), $eventHandler, $subscriptionId);

        $this->assertTrue($this->handlerConnections[(string) $this->relayUrl]->hasSubscription($subscriptionId));

        $handler
            ->expects($this->once())
            ->method('unsubscribe')
            ->with($this->relayUrl, $this->callback(
                static fn (SubscriptionId $id) => 'test-sub' === (string) $id
            ));

        $manager->disconnect($this->relayUrl);
    }

    public function testReconnectDisconnectsAndReconnects(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $handler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $handler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): void {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);
            });

        $manager->reconnect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testSubscribeEnsuresConnection(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $filter = new Filter();
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $manager->subscribe($this->relayUrl, $filter, $eventHandler);
    }

    public function testSubscribeReturnsGeneratedSubscriptionId(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $filter = new Filter();
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $handler
            ->expects($this->once())
            ->method('subscribe');

        $subscriptionId = $manager->subscribe($this->relayUrl, $filter, $eventHandler);

        $this->assertNotSame('', (string) $subscriptionId);
    }

    public function testSubscribeWithExplicitId(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $filter = new Filter();
        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $explicitId = SubscriptionId::fromString('my-subscription');

        $handler
            ->expects($this->once())
            ->method('subscribe')
            ->with($this->relayUrl, $explicitId, $filter, $eventHandler);

        $returnedId = $manager->subscribe($this->relayUrl, $filter, $eventHandler, $explicitId);

        $this->assertSame('my-subscription', (string) $returnedId);
    }

    public function testUnsubscribeRemovesSubscription(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $connection = $this->establishConnection();
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $subscriptionId = $manager->subscribe($this->relayUrl, new Filter(), $eventHandler);
        $manager->unsubscribe($this->relayUrl, $subscriptionId);

        $this->assertFalse($connection->hasSubscription($subscriptionId));
    }

    public function testPublishEventEnsuresConnection(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $pubkey = PublicKey::fromHex(str_pad('1', 64, '0', STR_PAD_LEFT));
        self::assertNotNull($pubkey);
        $event = EventFactory::createTextNote($pubkey, 'Test event');

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $manager->publishEvent($this->relayUrl, $event);
    }

    public function testPublishEventDelegatesToHandlerWhenConnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $pubkey = PublicKey::fromHex('abcd1234567890abcd1234567890abcd1234567890abcd1234567890abcd1234');
        self::assertNotNull($pubkey);
        $event = EventFactory::createTextNote($pubkey, 'Test event content');

        $handler
            ->expects($this->once())
            ->method('publishEvent')
            ->with($this->relayUrl, $event)
            ->willReturn(Future::complete(PublishResult::accepted()));

        $result = $manager->publishEvent($this->relayUrl, $event)->await();

        $this->assertTrue($result->isAccepted());
    }

    public function testGetConnectedRelays(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $connectedRelays = $manager->getConnectedRelays();
        $this->assertCount(1, $connectedRelays);
        $this->assertTrue($this->relayUrl->equals($connectedRelays->toArray()[0]->getRelayUrl()));
    }

    public function testGetConnectedRelaysReturnsEmptyWhenNoConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $result = $manager->getConnectedRelays();
        $this->assertTrue($result->isEmpty());
    }

    public function testGetConnectionStatusReturnsDisconnectedForUnknownRelay(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $status = $manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::DISCONNECTED, $status);
    }

    public function testGetConnectionStatusReturnsCorrectState(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $status = $manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::CONNECTED, $status);
    }

    public function testCloseDisconnectsAll(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $handler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $manager->close();

        $this->assertFalse($manager->isConnected($this->relayUrl));
    }

    public function testHealthCheckRunsOnAllConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $relay2 = RelayUrl::fromString('wss://relay2.example.com');
        self::assertNotNull($relay2);

        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $this->handlerConnections[(string) $relay2] = new RelayConnection($relay2, ConnectionState::CONNECTED, $config);

        $handler
            ->method('ping');

        $results = $manager->healthCheck();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertTrue($result->isHealthy());
        }
    }

    public function testGetAllConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $connection = $this->establishConnection();

        $connections = $manager->getAllConnections();

        $this->assertCount(1, $connections);
        $this->assertSame($connection, $connections->toArray()[0]);
    }

    public function testSetAuthHandlerDelegatesToConnectionHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $authHandler = $this->createStub(AuthChallengeHandlerInterface::class);

        $handler
            ->expects($this->once())
            ->method('setAuthHandler')
            ->with($authHandler);

        $manager->setAuthHandler($authHandler);
    }

    public function testSetReconnectionListenerDelegatesToConnectionHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $listener = $this->createStub(ReconnectionListenerInterface::class);

        $handler
            ->expects($this->once())
            ->method('setReconnectionListener')
            ->with($listener);

        $manager->setReconnectionListener($listener);
    }

    public function testPingDelegatesToConnectionHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $handler
            ->expects($this->once())
            ->method('ping')
            ->with($this->relayUrl);

        $manager->ping($this->relayUrl);
    }

    public function testPingEnsuresConnection(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $manager->ping($this->relayUrl);
    }

    public function testReconnectWithUnknownRelayUsesDefaultConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $handler
            ->method('disconnect')
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $handler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): void {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);
            });

        $manager->reconnect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testReconnectPreservesExistingConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig(connectionTimeoutSeconds: 30);

        $connectConfigs = [];
        $handler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $c) use (&$connectConfigs): void {
                $connectConfigs[] = $c;
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $c);
            });

        $handler
            ->method('disconnect')
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $manager->connect($this->relayUrl, $config);
        $manager->reconnect($this->relayUrl);

        $this->assertCount(2, $connectConfigs);
        $this->assertSame(30, $connectConfigs[1]->getConnectionTimeoutSeconds());
    }

    public function testSubscribeMultipleEnsuresConnection(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $manager->subscribeMultiple($this->relayUrl, new FilterCollection([new Filter()]), $eventHandler);
    }

    public function testSubscribeMultipleDelegatesToHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);
        $this->establishConnection();

        $filters = new FilterCollection([new Filter(), new Filter()]);
        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $explicitId = SubscriptionId::fromString('multi-sub');

        $handler
            ->expects($this->once())
            ->method('subscribeMultiple')
            ->with($this->relayUrl, $explicitId, $filters, $eventHandler);

        $returnedId = $manager->subscribeMultiple($this->relayUrl, $filters, $eventHandler, $explicitId);

        $this->assertSame('multi-sub', (string) $returnedId);
    }

    public function testUnsubscribeEnsuresConnection(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $subscriptionId = SubscriptionId::fromString('sub-1');
        self::assertNotNull($subscriptionId);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $manager->unsubscribe($this->relayUrl, $subscriptionId);
    }

    public function testDisconnectOnUnknownRelayIsNoop(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new ConnectionManager($handler);

        $handler
            ->expects($this->never())
            ->method('disconnect');

        $manager->disconnect($this->relayUrl);
    }

    public function testHealthCheckReturnsFailureOnPingError(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->method('ping')
            ->willThrowException(new ConnectionException('Ping failed'));

        $results = $manager->healthCheck();

        $this->assertCount(1, $results);

        foreach ($results as $result) {
            $this->assertFalse($result->isHealthy());
            $this->assertSame('Ping failed', $result->getErrorMessage());
        }
    }

    public function testHealthCheckReturnsEmptyForNoConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);

        $results = $manager->healthCheck();

        $this->assertTrue($results->isEmpty());
    }

    public function testGetConnectedRelaysExcludesUnhealthyConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new ConnectionManager($handler);
        $config = new ConnectionConfig();
        $healthyConnection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $relay2 = RelayUrl::fromString('wss://relay2.example.com');
        self::assertNotNull($relay2);
        $unhealthyConnection = new RelayConnection($relay2, ConnectionState::FAILED, $config);

        $this->handlerConnections[(string) $this->relayUrl] = $healthyConnection;
        $this->handlerConnections[(string) $relay2] = $unhealthyConnection;

        $connectedRelays = $manager->getConnectedRelays();

        $this->assertCount(1, $connectedRelays);
        $this->assertSame($healthyConnection, $connectedRelays->toArray()[0]);
    }
}
