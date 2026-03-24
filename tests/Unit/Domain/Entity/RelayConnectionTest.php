<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Entity;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RelayConnectionTest extends TestCase
{
    private RelayUrl $relayUrl;
    private ConnectionConfig $config;
    private RelayConnection $connection;

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
        $this->config = new ConnectionConfig();
        $this->connection = new RelayConnection($this->relayUrl, ConnectionState::DISCONNECTED, $this->config);
    }

    public function testConstructorSetsInitialState(): void
    {
        $this->assertSame($this->relayUrl, $this->connection->getRelayUrl());
        $this->assertSame(ConnectionState::DISCONNECTED, $this->connection->getState());
        $this->assertSame($this->config, $this->connection->getConfig());
        $this->assertNull($this->connection->getConnectedAt());
    }

    public function testConstructorSetsConnectedAtWhenInitiallyConnected(): void
    {
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $this->config);

        $this->assertTrue($connection->isHealthy());
        $this->assertNotNull($connection->getConnectedAt());
    }

    public function testUpdateStateChangesConnectionState(): void
    {
        $this->connection->updateState(ConnectionState::CONNECTED);
        $this->assertSame(ConnectionState::CONNECTED, $this->connection->getState());
        $this->assertNotNull($this->connection->getConnectedAt());
    }

    public function testUpdateStateRejectsInvalidTransition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid state transition from disconnected to failed');

        $this->connection->updateState(ConnectionState::FAILED);
    }

    public function testSubscriptionManagement(): void
    {
        $subscriptionId = SubscriptionId::generate();
        $filter = new Filter();

        $this->assertFalse($this->connection->hasSubscription($subscriptionId));
        $this->assertSame(0, $this->connection->getSubscriptionCount());

        $this->connection->addSubscription($subscriptionId, [$filter]);

        $this->assertTrue($this->connection->hasSubscription($subscriptionId));
        $this->assertSame(1, $this->connection->getSubscriptionCount());

        $subscriptions = $this->connection->getSubscriptions();
        $this->assertTrue($subscriptions->has($subscriptionId));

        $this->connection->removeSubscription($subscriptionId);

        $this->assertFalse($this->connection->hasSubscription($subscriptionId));
        $this->assertSame(0, $this->connection->getSubscriptionCount());
    }

    public function testHealthyStatusDetermination(): void
    {
        $this->assertFalse($this->connection->isHealthy());

        $this->connection->updateState(ConnectionState::CONNECTED);
        $this->assertTrue($this->connection->isHealthy());

        $this->connection->updateState(ConnectionState::FAILED);
        $this->assertFalse($this->connection->isHealthy());
    }

    public function testSubscriptionDefaultStateIsPending(): void
    {
        $subscriptionId = SubscriptionId::generate();
        $filter = new Filter();

        $this->connection->addSubscription($subscriptionId, [$filter]);

        $this->assertSame(SubscriptionState::PENDING, $this->connection->getSubscriptionState($subscriptionId));
    }

    public function testSubscriptionWithExplicitInitialState(): void
    {
        $subscriptionId = SubscriptionId::generate();
        $filter = new Filter();

        $this->connection->addSubscription($subscriptionId, [$filter], SubscriptionState::ACTIVE);

        $this->assertSame(SubscriptionState::ACTIVE, $this->connection->getSubscriptionState($subscriptionId));
    }

    public function testUpdateSubscriptionState(): void
    {
        $subscriptionId = SubscriptionId::generate();
        $filter = new Filter();

        $this->connection->addSubscription($subscriptionId, [$filter]);
        $this->assertSame(SubscriptionState::PENDING, $this->connection->getSubscriptionState($subscriptionId));

        $this->assertTrue($this->connection->updateSubscriptionState($subscriptionId, SubscriptionState::ACTIVE));
        $this->assertSame(SubscriptionState::ACTIVE, $this->connection->getSubscriptionState($subscriptionId));

        $this->assertTrue($this->connection->updateSubscriptionState($subscriptionId, SubscriptionState::LIVE));
        $this->assertSame(SubscriptionState::LIVE, $this->connection->getSubscriptionState($subscriptionId));
    }

    public function testGetSubscriptionStateReturnsNullForUnknownSubscription(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $this->assertNull($this->connection->getSubscriptionState($subscriptionId));
    }

    public function testUpdateSubscriptionStateReturnsFalseForUnknownSubscription(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $this->assertFalse($this->connection->updateSubscriptionState($subscriptionId, SubscriptionState::ACTIVE));
        $this->assertNull($this->connection->getSubscriptionState($subscriptionId));
    }

    public function testClearSubscriptions(): void
    {
        $this->connection->addSubscription(SubscriptionId::generate(), [new Filter()]);
        $this->connection->addSubscription(SubscriptionId::generate(), [new Filter()]);

        $this->assertSame(2, $this->connection->getSubscriptionCount());

        $this->connection->clearSubscriptions();

        $this->assertSame(0, $this->connection->getSubscriptionCount());
        $this->assertTrue($this->connection->getSubscriptions()->isEmpty());
    }
}
