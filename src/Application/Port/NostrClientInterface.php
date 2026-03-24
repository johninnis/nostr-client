<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResultCollection;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

interface NostrClientInterface
{
    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void;

    public function connect(RelayUrl $relay, ?ConnectionConfig $config = null): void;

    public function disconnect(RelayUrl $relay): void;

    public function reconnect(RelayUrl $relay): void;

    public function publishEvent(RelayUrl $relay, Event $event): bool;

    public function subscribe(
        RelayUrl $relay,
        Filter $filter,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId;

    public function subscribeMultiple(
        RelayUrl $relay,
        array $filters,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId;

    public function unsubscribe(RelayUrl $relay, SubscriptionId $subscriptionId): void;

    public function isConnected(RelayUrl $relay): bool;

    public function ping(RelayUrl $relay): bool;

    public function getConnection(RelayUrl $relay): ?RelayConnection;

    public function getConnectedRelays(): RelayConnectionCollection;

    public function getAllConnections(): RelayConnectionCollection;

    public function getConnectionStatus(RelayUrl $relay): ConnectionState;

    public function close(): void;

    public function healthCheck(): HealthCheckResultCollection;
}
