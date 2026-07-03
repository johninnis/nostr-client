<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Entity;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
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
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com');
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
        $this->assertFalse($this->connection->isHealthy());
    }

    public function testConstructorIsHealthyWhenInitiallyConnected(): void
    {
        $connection = new RelayConnection($this->relayUrl, ConnectionState::CONNECTED, $this->config);

        $this->assertTrue($connection->isHealthy());
    }

    public function testWithStateReturnsANewConnectionLeavingTheOriginalUnchanged(): void
    {
        $connected = $this->connection->withState(ConnectionState::CONNECTED);

        $this->assertSame(ConnectionState::CONNECTED, $connected->getState());
        $this->assertTrue($connected->isHealthy());
        $this->assertSame(ConnectionState::DISCONNECTED, $this->connection->getState());
    }

    public function testWithStateRejectsInvalidTransition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid state transition from disconnected to failed');

        $this->connection->withState(ConnectionState::FAILED);
    }

    public function testSubscriptionManagement(): void
    {
        $subscriptionId = SubscriptionId::generate();
        $filter = new Filter();

        $this->assertFalse($this->connection->hasSubscription($subscriptionId));
        $this->assertSame(0, $this->connection->getSubscriptionCount());

        $withSub = $this->connection->withSubscription($subscriptionId, new FilterCollection([$filter]));

        $this->assertTrue($withSub->hasSubscription($subscriptionId));
        $this->assertSame(1, $withSub->getSubscriptionCount());
        $this->assertTrue($withSub->getSubscriptions()->has($subscriptionId));
        $this->assertFalse($this->connection->hasSubscription($subscriptionId));

        $withoutSub = $withSub->withoutSubscription($subscriptionId);

        $this->assertFalse($withoutSub->hasSubscription($subscriptionId));
        $this->assertSame(0, $withoutSub->getSubscriptionCount());
    }

    public function testHealthyStatusDetermination(): void
    {
        $this->assertFalse($this->connection->isHealthy());

        $connected = $this->connection->withState(ConnectionState::CONNECTED);
        $this->assertTrue($connected->isHealthy());

        $failed = $connected->withState(ConnectionState::FAILED);
        $this->assertFalse($failed->isHealthy());
    }

    public function testSubscriptionDefaultStateIsPending(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $connection = $this->connection->withSubscription($subscriptionId, new FilterCollection([new Filter()]));

        $this->assertSame(SubscriptionState::Pending, $connection->getSubscriptionState($subscriptionId));
    }

    public function testSubscriptionWithExplicitInitialState(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $connection = $this->connection->withSubscription($subscriptionId, new FilterCollection([new Filter()]), SubscriptionState::Active);

        $this->assertSame(SubscriptionState::Active, $connection->getSubscriptionState($subscriptionId));
    }

    public function testWithSubscriptionStateAdvancesTheState(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $pending = $this->connection->withSubscription($subscriptionId, new FilterCollection([new Filter()]));
        $this->assertSame(SubscriptionState::Pending, $pending->getSubscriptionState($subscriptionId));

        $active = $pending->withSubscriptionState($subscriptionId, SubscriptionState::Active);
        $this->assertSame(SubscriptionState::Active, $active->getSubscriptionState($subscriptionId));

        $live = $active->withSubscriptionState($subscriptionId, SubscriptionState::Live);
        $this->assertSame(SubscriptionState::Live, $live->getSubscriptionState($subscriptionId));
    }

    public function testGetSubscriptionStateReturnsNullForUnknownSubscription(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $this->assertNull($this->connection->getSubscriptionState($subscriptionId));
    }

    public function testWithSubscriptionStateIgnoresUnknownSubscription(): void
    {
        $subscriptionId = SubscriptionId::generate();

        $connection = $this->connection->withSubscriptionState($subscriptionId, SubscriptionState::Active);

        $this->assertNull($connection->getSubscriptionState($subscriptionId));
    }

    public function testWithoutSubscriptions(): void
    {
        $connection = $this->connection
            ->withSubscription(SubscriptionId::generate(), new FilterCollection([new Filter()]))
            ->withSubscription(SubscriptionId::generate(), new FilterCollection([new Filter()]));

        $this->assertSame(2, $connection->getSubscriptionCount());

        $cleared = $connection->withoutSubscriptions();

        $this->assertSame(0, $cleared->getSubscriptionCount());
        $this->assertTrue($cleared->getSubscriptions()->isEmpty());
    }
}
