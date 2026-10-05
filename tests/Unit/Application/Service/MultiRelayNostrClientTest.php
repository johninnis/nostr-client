<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Application\Service;

use Amp\Future;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Application\Service\MultiRelayNostrClient;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\ConnectResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class MultiRelayNostrClientTest extends TestCase
{
    private RelayUrl $relayUrl;
    /** @var array<string, RelayConnection> */
    private array $handlerConnections = [];

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com');
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
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->expects($this->once())
            ->method('connect')
            ->with($this->relayUrl, $config)
            ->willReturnCallback(function () use ($connection): ConnectResult {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;

                return ConnectResult::connected();
            });

        $manager->connect($this->relayUrl, $config);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectWithDefaultConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        $handler
            ->method('connect')
            ->willReturnCallback(function () use ($connection): ConnectResult {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;

                return ConnectResult::connected();
            });

        $manager->connect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectReturnsExistingHealthyConnection(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->expects($this->once())
            ->method('connect')
            ->willReturnCallback(function () use ($connection): ConnectResult {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;

                return ConnectResult::connected();
            });

        $manager->connect($this->relayUrl, $config);
        $manager->connect($this->relayUrl, $config);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testConnectReturnsTheConnectedResult(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        $handler
            ->method('connect')
            ->willReturnCallback(function () use ($connection): ConnectResult {
                $this->handlerConnections[(string) $this->relayUrl] = $connection;

                return ConnectResult::connected();
            });

        $this->assertTrue($manager->connect($this->relayUrl)->isConnected());
    }

    public function testConnectToAnAlreadyConnectedRelayReturnsConnected(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $this->assertTrue($manager->connect($this->relayUrl)->isConnected());
    }

    public function testConnectReturnsFailedToConnectWhenTheRelayCannotBeReached(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $handler
            ->method('connect')
            ->willReturn(ConnectResult::failedToConnect());

        $result = $manager->connect($this->relayUrl);

        $this->assertFalse($result->isConnected());
        $this->assertSame('failed to connect', $result->getMessage());
    }

    public function testConnectReturnsFailedToConnectWhenAFailedConnectionCannotBeRestored(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $connectCallCount = 0;
        $handler
            ->method('connect')
            ->willReturnCallback(function () use (&$connectCallCount, $connection): ConnectResult {
                ++$connectCallCount;
                if (1 !== $connectCallCount) {
                    return ConnectResult::failedToConnect();
                }

                $this->handlerConnections[(string) $this->relayUrl] = $connection;

                return ConnectResult::connected();
            });

        $manager->connect($this->relayUrl, $config);
        $this->handlerConnections[(string) $this->relayUrl] = $connection->withState(ConnectionState::FAILED);

        $this->assertFalse($manager->connect($this->relayUrl, $config)->isConnected());
    }

    public function testConnectPropagatesAFaultOfTheClient(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $handler
            ->method('connect')
            ->willThrowException(new LogicException('broken invariant'));

        $this->expectException(LogicException::class);

        $manager->connect($this->relayUrl);
    }

    public function testDisconnectRemovesConnection(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
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
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $subscriptionId = SubscriptionId::tryFromString('test-sub');
        self::assertNotNull($subscriptionId);

        $this->handlerConnections[(string) $this->relayUrl] = $connection;

        $handler
            ->method('subscribe')
            ->willReturnCallback(function () use ($subscriptionId): void {
                $url = (string) $this->relayUrl;
                $this->handlerConnections[$url] = $this->handlerConnections[$url]
                    ->withSubscription($subscriptionId, new FilterCollection([Filter::from()]));
            });

        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $manager->subscribe(SubscriptionRequest::for($this->relayUrl, Filter::from(), $subscriptionId), $eventHandler);

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
        $manager = new MultiRelayNostrClient($handler);
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
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): ConnectResult {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);

                return ConnectResult::connected();
            });

        $manager->reconnect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testSubscribeToAnUnconnectedRelayClosesTheSubscriptionAsDisconnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $eventHandler = $this->createMock(EventHandlerInterface::class);
        $subscriptionId = SubscriptionId::tryFromString('offline-sub');
        self::assertNotNull($subscriptionId);

        $handler->expects($this->never())->method('subscribe');
        $eventHandler
            ->expects($this->once())
            ->method('handleClosed')
            ->with($subscriptionId, 'disconnected');

        $manager->subscribe(SubscriptionRequest::for($this->relayUrl, Filter::from(), $subscriptionId), $eventHandler);
    }

    public function testSubscribeToAnUnconnectedRelayStillReturnsTheSubscriptionId(): void
    {
        $manager = new MultiRelayNostrClient($this->createHandlerStub());

        $subscriptionId = $manager->subscribe(SubscriptionRequest::for($this->relayUrl, Filter::from()), $this->createStub(EventHandlerInterface::class));

        $this->assertNotSame('', (string) $subscriptionId);
    }

    public function testSubscribeReturnsGeneratedSubscriptionId(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $filter = Filter::from();
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $handler
            ->expects($this->once())
            ->method('subscribe');

        $subscriptionId = $manager->subscribe(SubscriptionRequest::for($this->relayUrl, $filter), $eventHandler);

        $this->assertNotSame('', (string) $subscriptionId);
    }

    public function testSubscribeWithExplicitId(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $filter = Filter::from();
        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $explicitId = SubscriptionId::tryFromString('my-subscription');

        $handler
            ->expects($this->once())
            ->method('subscribe')
            ->with(SubscriptionRequest::for($this->relayUrl, $filter, $explicitId), $eventHandler);

        $returnedId = $manager->subscribe(SubscriptionRequest::for($this->relayUrl, $filter, $explicitId), $eventHandler);

        $this->assertSame('my-subscription', (string) $returnedId);
    }

    public function testUnsubscribeRemovesSubscription(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $connection = $this->establishConnection();
        $eventHandler = $this->createStub(EventHandlerInterface::class);

        $subscriptionId = $manager->subscribe(SubscriptionRequest::for($this->relayUrl, Filter::from()), $eventHandler);
        $manager->unsubscribe($this->relayUrl, $subscriptionId);

        $this->assertFalse($connection->hasSubscription($subscriptionId));
    }

    public function testPublishEventToAnUnconnectedRelayResolvesAsDisconnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);

        $handler->expects($this->never())->method('publishEvent');

        $result = $manager->publishEvent($this->relayUrl, EventMother::textNote('Test event'))->await();

        $this->assertFalse($result->isAccepted());
        $this->assertSame('disconnected', $result->getMessage());
    }

    public function testPublishEventDelegatesToHandlerWhenConnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $event = EventMother::textNote('Test event content');

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
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $connectedRelays = $manager->getConnectedRelays();
        $this->assertCount(1, $connectedRelays);
        $this->assertTrue($this->relayUrl->equals($connectedRelays->toArray()[0]->getRelayUrl()));
    }

    public function testGetConnectedRelaysReturnsEmptyWhenNoConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $result = $manager->getConnectedRelays();
        $this->assertTrue($result->isEmpty());
    }

    public function testGetConnectionStatusReturnsDisconnectedForUnknownRelay(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $status = $manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::DISCONNECTED, $status);
    }

    public function testGetConnectionStatusReturnsCorrectState(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $status = $manager->getConnectionStatus($this->relayUrl);
        $this->assertSame(ConnectionState::CONNECTED, $status);
    }

    public function testCloseDisconnectsAll(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
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
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $relay2 = RelayUrl::tryFromString('wss://relay2.example.com');
        self::assertNotNull($relay2);

        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $this->handlerConnections[(string) $relay2] = new RelayConnection($relay2, ConnectionState::CONNECTED, $config);

        $handler
            ->method('ping')
            ->willReturnCallback(static fn (RelayUrl $url): HealthCheckResult => HealthCheckResult::success($url));

        $results = $manager->healthCheck();

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            $this->assertTrue($result->isHealthy());
        }
    }

    public function testGetAllConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $connection = $this->establishConnection();

        $connections = $manager->getAllConnections();

        $this->assertCount(1, $connections);
        $this->assertSame($connection, $connections->toArray()[0]);
    }

    public function testSetAuthHandlerDelegatesToConnectionHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
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
        $manager = new MultiRelayNostrClient($handler);
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
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $handler
            ->expects($this->once())
            ->method('ping')
            ->with($this->relayUrl)
            ->willReturn(HealthCheckResult::success($this->relayUrl));

        $this->assertTrue($manager->ping($this->relayUrl)->isHealthy());
    }

    public function testPingOfAnUnconnectedRelayReportsItDisconnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);

        $handler->expects($this->never())->method('ping');

        $result = $manager->ping($this->relayUrl);

        $this->assertFalse($result->isHealthy());
        $this->assertSame('disconnected', $result->getErrorMessage());
    }

    public function testReconnectWithUnknownRelayUsesDefaultConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $handler
            ->method('disconnect')
            ->willReturnCallback(function (RelayUrl $url): void {
                unset($this->handlerConnections[(string) $url]);
            });

        $handler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $config): ConnectResult {
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $config);

                return ConnectResult::connected();
            });

        $manager->reconnect($this->relayUrl);

        $this->assertTrue($manager->isConnected($this->relayUrl));
    }

    public function testReconnectPreservesExistingConfig(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig(connectionTimeoutSeconds: 30);

        $connectConfigs = [];
        $handler
            ->method('connect')
            ->willReturnCallback(function (RelayUrl $url, ConnectionConfig $c) use (&$connectConfigs): ConnectResult {
                $connectConfigs[] = $c;
                $this->handlerConnections[(string) $url] = new RelayConnection($url, ConnectionState::CONNECTED, $c);

                return ConnectResult::connected();
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

    public function testSubscribeWithMultipleFiltersToAnUnconnectedRelayClosesTheSubscriptionAsDisconnected(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $eventHandler = $this->createMock(EventHandlerInterface::class);
        $subscriptionId = SubscriptionId::tryFromString('offline-multi');
        self::assertNotNull($subscriptionId);

        $handler->expects($this->never())->method('subscribe');
        $eventHandler
            ->expects($this->once())
            ->method('handleClosed')
            ->with($subscriptionId, 'disconnected');

        $manager->subscribe(new SubscriptionRequest($this->relayUrl, new FilterCollection([Filter::from()]), $subscriptionId), $eventHandler);
    }

    public function testSubscribeWithMultipleFiltersDelegatesToHandler(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);
        $this->establishConnection();

        $filters = new FilterCollection([Filter::from(), Filter::from()]);
        $eventHandler = $this->createStub(EventHandlerInterface::class);
        $explicitId = SubscriptionId::tryFromString('multi-sub');

        $handler
            ->expects($this->once())
            ->method('subscribe')
            ->with(new SubscriptionRequest($this->relayUrl, $filters, $explicitId), $eventHandler);

        $returnedId = $manager->subscribe(new SubscriptionRequest($this->relayUrl, $filters, $explicitId), $eventHandler);

        $this->assertSame('multi-sub', (string) $returnedId);
    }

    public function testUnsubscribeFromAnUnconnectedRelayIsANoop(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);

        $subscriptionId = SubscriptionId::tryFromString('sub-1');
        self::assertNotNull($subscriptionId);

        $handler->expects($this->never())->method('unsubscribe');

        $manager->unsubscribe($this->relayUrl, $subscriptionId);
    }

    public function testDisconnectOnUnknownRelayIsNoop(): void
    {
        $handler = $this->createHandlerMock();
        $manager = new MultiRelayNostrClient($handler);

        $handler
            ->expects($this->never())
            ->method('disconnect');

        $manager->disconnect($this->relayUrl);
    }

    public function testHealthCheckReportsARelayWhosePingFails(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $this->handlerConnections[(string) $this->relayUrl] = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);

        $handler
            ->method('ping')
            ->willReturn(HealthCheckResult::failure($this->relayUrl, 'disconnected'));

        $results = $manager->healthCheck();

        $this->assertCount(1, $results);

        foreach ($results as $result) {
            $this->assertFalse($result->isHealthy());
            $this->assertSame('disconnected', $result->getErrorMessage());
        }
    }

    public function testHealthCheckReturnsEmptyForNoConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);

        $results = $manager->healthCheck();

        $this->assertTrue($results->isEmpty());
    }

    public function testGetConnectedRelaysExcludesUnhealthyConnections(): void
    {
        $handler = $this->createHandlerStub();
        $manager = new MultiRelayNostrClient($handler);
        $config = new ConnectionConfig();
        $healthyConnection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $config);
        $relay2 = RelayUrl::tryFromString('wss://relay2.example.com');
        self::assertNotNull($relay2);
        $unhealthyConnection = new RelayConnection($relay2, ConnectionState::FAILED, $config);

        $this->handlerConnections[(string) $this->relayUrl] = $healthyConnection;
        $this->handlerConnections[(string) $relay2] = $unhealthyConnection;

        $connectedRelays = $manager->getConnectedRelays();

        $this->assertCount(1, $connectedRelays);
        $this->assertSame($healthyConnection, $connectedRelays->toArray()[0]);
    }
}
