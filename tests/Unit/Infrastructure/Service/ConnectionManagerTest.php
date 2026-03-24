<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Service;

use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResultCollection;
use Innis\Nostr\Client\Infrastructure\Service\ConnectionManager;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Factory\EventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerTest extends TestCase
{
    private ConnectionHandlerInterface&MockObject $connectionHandler;
    private ConnectionManager $manager;
    private RelayUrl $relayUrl;
    private array $handlerConnections = [];

    protected function setUp(): void
    {
        $this->connectionHandler = $this->createMock(ConnectionHandlerInterface::class);

        $this->connectionHandler
            ->method('getConnection')
            ->willReturnCallback(fn (RelayUrl $url) => $this->handlerConnections[(string) $url] ?? null);

        $this->connectionHandler
            ->method('isConnected')
            ->willReturnCallback(fn (RelayUrl $url) => isset($this->handlerConnections[(string) $url]) && $this->handlerConnections[(string) $url]->isHealthy());

        $this->connectionHandler
            ->method('getAllConnections')
            ->willReturnCallback(fn () => new RelayConnectionCollection(array_values($this->handlerConnections)));

        $this->manager = new ConnectionManager($this->connectionHandler);

        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
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
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $this->connectionHandler
            ->expects($this->once())
            ->method('connect')
            ->with($this->relayUrl, $config)
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $this->manager->connect($this->relayUrl, $config);

        $this->assertTrue($this->manager->isConnected($this->relayUrl));
    }

    public function testConnectWithDefaultConfig(): void
    {
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        $this->connectionHandler
            ->method('connect')
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $this->manager->connect($this->relayUrl);

        $this->assertTrue($this->manager->isConnected($this->relayUrl));
    }

    public function testConnectReturnsExistingHealthyConnection(): void
    {
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $this->connectionHandler
            ->expects($this->once())
            ->method('connect')
            ->willReturnCallback(function () use ($connection): void {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;
            });

        $this->manager->connect($this->relayUrl, $config);
        $this->manager->connect($this->relayUrl, $config);

        $this->assertTrue($this->manager->isConnected($this->relayUrl));
    }

    public function testConnectThrowsOnFailure(): void
    {
        $config = new ConnectionConfig();
        $exception = new ConnectionException('Connection failed');

        $this->connectionHandler
            ->expects($this->once())
            ->method('connect')
            ->willThrowException($exception);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection failed');

        $this->manager->connect($this->relayUrl, $config);
    }

    public function testConnectRecordsFailureWhenConnectionExists(): void
    {
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $exception = new ConnectionException('Connection failed');

        $connectCallCount = 0;
        $this->connectionHandler
            ->method('connect')
            ->willReturnCallback(function () use (&$connectCallCount, $connection, $exception): void {
                ++$connectCallCount;
                if (1 === $connectCallCount) {
                    $this->handlerConnections[(string) $this->relayUrl] = $connection;
                } else {
                    throw $exception;
                }
            });

        $this->manager->connect($this->relayUrl, $config);
        $connection->updateState(ConnectionState::FAILED);

        $this->expectException(ConnectionException::class);

        $this->manager->connect($this->relayUrl, $config);
    }

    public function testDisconnectRemovesConnection(): void
    {
        $this->establishConnection();

        $this->connectionHandler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $this->manager->disconnect($this->relayUrl);

        $this->assertFalse($this->manager->isConnected($this->relayUrl));
        $this->assertNull($this->manager->getConnection($this->relayUrl));
    }

    public function testDisconnectUnsubscribesAll(): void
    {
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $subscriptionId = SubscriptionId::fromString('test-sub');

        $this->handlerConnections[(string) $this->relayUrl] = $connection;

        $this->connectionHandler
            ->method('subscribe')
            ->willReturnCallback(static function () use ($connection, $subscriptionId): void {
                $connection->addSubscription($subscriptionId, [new Filter()]);
            });

        $handler = $this->createMock(EventHandlerInterface::class);
        $this->manager->subscribe($this->relayUrl, new Filter(), $handler, $subscriptionId);

        $this->assertTrue($connection->hasSubscription($subscriptionId));

        $this->connectionHandler
            ->expects($this->once())
            ->method('unsubscribe')
            ->with($this->relayUrl, $this->callback(
                static fn (SubscriptionId $id) => 'test-sub' === (string) $id
            ));

        $this->manager->disconnect($this->relayUrl);
    }

    public function testReconnectDisconnectsAndReconnects(): void
    {
        $this->establishConnection();

        $this->connectionHandler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $this->connectionHandler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): void {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);
            });

        $this->manager->reconnect($this->relayUrl);

        $this->assertTrue($this->manager->isConnected($this->relayUrl));
    }

    public function testSubscribeEnsuresConnection(): void
    {
        $filter = new Filter();
        $handler = $this->createMock(EventHandlerInterface::class);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $this->manager->subscribe($this->relayUrl, $filter, $handler);
    }

    public function testSubscribeReturnsGeneratedSubscriptionId(): void
    {
        $this->establishConnection();

        $filter = new Filter();
        $handler = $this->createMock(EventHandlerInterface::class);

        $this->connectionHandler
            ->expects($this->once())
            ->method('subscribe');

        $subscriptionId = $this->manager->subscribe($this->relayUrl, $filter, $handler);

        $this->assertInstanceOf(SubscriptionId::class, $subscriptionId);
    }

    public function testSubscribeWithExplicitId(): void
    {
        $this->establishConnection();

        $filter = new Filter();
        $handler = $this->createMock(EventHandlerInterface::class);
        $explicitId = SubscriptionId::fromString('my-subscription');

        $this->connectionHandler
            ->expects($this->once())
            ->method('subscribe')
            ->with($this->relayUrl, $explicitId, $filter, $handler);

        $returnedId = $this->manager->subscribe($this->relayUrl, $filter, $handler, $explicitId);

        $this->assertSame('my-subscription', (string) $returnedId);
    }

    public function testUnsubscribeRemovesSubscription(): void
    {
        $connection = $this->establishConnection();
        $handler = $this->createMock(EventHandlerInterface::class);

        $subscriptionId = $this->manager->subscribe($this->relayUrl, new Filter(), $handler);
        $this->manager->unsubscribe($this->relayUrl, $subscriptionId);

        $this->assertFalse($connection->hasSubscription($subscriptionId));
    }

    public function testPublishEventEnsuresConnection(): void
    {
        $pubkey = PublicKey::fromHex(str_pad('1', 64, '0', STR_PAD_LEFT));
        self::assertNotNull($pubkey);
        $event = EventFactory::createTextNote($pubkey, 'Test event');

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $this->manager->publishEvent($this->relayUrl, $event);
    }

    public function testPublishEvent(): void
    {
        $this->establishConnection();

        $pubkey = PublicKey::fromHex('abcd1234567890abcd1234567890abcd1234567890abcd1234567890abcd1234');
        self::assertNotNull($pubkey);
        $event = EventFactory::createTextNote($pubkey, 'Test event content');

        $this->connectionHandler
            ->method('publishEvent')
            ->willReturn(true);

        $result = $this->manager->publishEvent($this->relayUrl, $event);
        $this->assertTrue($result);
    }

    public function testGetConnectedRelays(): void
    {
        $this->establishConnection();

        $connectedRelays = $this->manager->getConnectedRelays();
        $this->assertCount(1, $connectedRelays);
        $this->assertTrue($this->relayUrl->equals($connectedRelays->toArray()[0]->getRelayUrl()));
    }

    public function testGetConnectedRelaysReturnsEmptyWhenNoConnections(): void
    {
        $result = $this->manager->getConnectedRelays();
        $this->assertTrue($result->isEmpty());
    }

    public function testGetConnectionStatusReturnsDisconnectedForUnknownRelay(): void
    {
        $status = $this->manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::DISCONNECTED, $status);
    }

    public function testGetConnectionStatusReturnsCorrectState(): void
    {
        $this->establishConnection();

        $status = $this->manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::CONNECTED, $status);
    }

    public function testCloseDisconnectsAll(): void
    {
        $this->establishConnection();

        $this->connectionHandler
            ->expects($this->once())
            ->method('disconnect')
            ->with($this->relayUrl)
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $this->manager->close();

        $this->assertFalse($this->manager->isConnected($this->relayUrl));
    }

    public function testHealthCheckRunsOnAllConnections(): void
    {
        $config = new ConnectionConfig();
        $relay2 = RelayUrl::fromString('wss://relay2.example.com');
        self::assertNotNull($relay2);

        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $this->handlerConnections[(string) $relay2] = new RelayConnection($relay2, ConnectionState::CONNECTED, $config);

        $this->connectionHandler
            ->method('ping')
            ->willReturn(true);

        $results = $this->manager->healthCheck();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(HealthCheckResult::class, $result);
            $this->assertTrue($result->isHealthy());
        }
    }

    public function testGetAllConnections(): void
    {
        $connection = $this->establishConnection();

        $connections = $this->manager->getAllConnections();

        $this->assertCount(1, $connections);
        $this->assertSame($connection, $connections->toArray()[0]);
    }

    public function testSetAuthHandlerDelegatesToConnectionHandler(): void
    {
        $authHandler = $this->createMock(AuthChallengeHandlerInterface::class);

        $this->connectionHandler
            ->expects($this->once())
            ->method('setAuthHandler')
            ->with($authHandler);

        $this->manager->setAuthHandler($authHandler);
    }

    public function testPingDelegatesToConnectionHandler(): void
    {
        $this->establishConnection();

        $this->connectionHandler
            ->expects($this->once())
            ->method('ping')
            ->with($this->relayUrl)
            ->willReturn(true);

        $this->assertTrue($this->manager->ping($this->relayUrl));
    }

    public function testPingEnsuresConnection(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $this->manager->ping($this->relayUrl);
    }

    public function testReconnectWithUnknownRelayUsesDefaultConfig(): void
    {
        $this->connectionHandler
            ->method('disconnect')
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $this->connectionHandler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): void {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);
            });

        $this->manager->reconnect($this->relayUrl);

        $this->assertTrue($this->manager->isConnected($this->relayUrl));
    }

    public function testReconnectPreservesExistingConfig(): void
    {
        $config = new ConnectionConfig(connectionTimeoutSeconds: 30);

        $connectConfigs = [];
        $this->connectionHandler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $c) use (&$connectConfigs): void {
                $connectConfigs[] = $c;
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $c);
            });

        $this->connectionHandler
            ->method('disconnect')
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $this->manager->connect($this->relayUrl, $config);
        $this->manager->reconnect($this->relayUrl);

        $this->assertCount(2, $connectConfigs);
        $this->assertSame(30, $connectConfigs[1]->getConnectionTimeoutSeconds());
    }

    public function testSubscribeMultipleEnsuresConnection(): void
    {
        $handler = $this->createMock(EventHandlerInterface::class);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $this->manager->subscribeMultiple($this->relayUrl, [new Filter()], $handler);
    }

    public function testSubscribeMultipleDelegatesToHandler(): void
    {
        $this->establishConnection();

        $filters = [new Filter(), new Filter()];
        $handler = $this->createMock(EventHandlerInterface::class);
        $explicitId = SubscriptionId::fromString('multi-sub');

        $this->connectionHandler
            ->expects($this->once())
            ->method('subscribeMultiple')
            ->with($this->relayUrl, $explicitId, $filters, $handler);

        $returnedId = $this->manager->subscribeMultiple($this->relayUrl, $filters, $handler, $explicitId);

        $this->assertSame('multi-sub', (string) $returnedId);
    }

    public function testUnsubscribeEnsuresConnection(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Not connected');

        $this->manager->unsubscribe($this->relayUrl, SubscriptionId::fromString('sub-1'));
    }

    public function testDisconnectOnUnknownRelayIsNoop(): void
    {
        $this->connectionHandler
            ->expects($this->never())
            ->method('disconnect');

        $this->manager->disconnect($this->relayUrl);
    }

    public function testHealthCheckReturnsFailureOnPingError(): void
    {
        $config = new ConnectionConfig();
        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $this->connectionHandler
            ->method('ping')
            ->willThrowException(new ConnectionException('Ping failed'));

        $results = $this->manager->healthCheck();

        $this->assertInstanceOf(HealthCheckResultCollection::class, $results);
        $this->assertCount(1, $results);

        foreach ($results as $result) {
            $this->assertFalse($result->isHealthy());
            $this->assertSame('Ping failed', $result->getErrorMessage());
        }
    }

    public function testHealthCheckReturnsEmptyForNoConnections(): void
    {
        $results = $this->manager->healthCheck();

        $this->assertInstanceOf(HealthCheckResultCollection::class, $results);
        $this->assertTrue($results->isEmpty());
    }

    public function testGetConnectedRelaysExcludesUnhealthyConnections(): void
    {
        $config = new ConnectionConfig();
        $healthyConnection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $relay2 = RelayUrl::fromString('wss://relay2.example.com');
        self::assertNotNull($relay2);
        $unhealthyConnection = new RelayConnection($relay2, ConnectionState::FAILED, $config);

        $this->handlerConnections[(string) $this->relayUrl] = $healthyConnection;
        $this->handlerConnections[(string) $relay2] = $unhealthyConnection;

        $connectedRelays = $this->manager->getConnectedRelays();

        $this->assertCount(1, $connectedRelays);
        $this->assertSame($healthyConnection, $connectedRelays->toArray()[0]);
    }
}
