<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Service;

use Amp\Future;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\AuthResultListenerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\Collection\HealthCheckResultCollection;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

interface NostrClientInterface
{
    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void;

    public function setReconnectionListener(ReconnectionListenerInterface $listener): void;

    public function setAuthResultListener(AuthResultListenerInterface $listener): void;

    public function connect(RelayUrl $relay, ?ConnectionConfig $config = null): void;

    public function disconnect(RelayUrl $relay): void;

    public function reconnect(RelayUrl $relay): void;

    /**
     * @return Future<PublishResult>
     */
    public function publishEvent(RelayUrl $relay, Event $event): Future;

    public function awaitPendingPublishes(RelayUrl $relay, ?float $timeoutSeconds = null): void;

    // Deliberate: relay target, filter, handler sink and optional correlation id are the irreducible inputs of a NIP-01 REQ; the handler is a collaborator, not data, so there is no cohesive value object to extract.
    public function subscribe(
        RelayUrl $relay,
        Filter $filter,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId;

    // Deliberate: relay target, filters, handler sink and optional correlation id are the irreducible inputs of a NIP-01 REQ; the handler is a collaborator, not data, so there is no cohesive value object to extract.
    public function subscribeMultiple(
        RelayUrl $relay,
        FilterCollection $filters,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId;

    public function unsubscribe(RelayUrl $relay, SubscriptionId $subscriptionId): void;

    public function isConnected(RelayUrl $relay): bool;

    public function ping(RelayUrl $relay): void;

    public function getConnection(RelayUrl $relay): ?RelayConnection;

    public function getConnectedRelays(): RelayConnectionCollection;

    public function getAllConnections(): RelayConnectionCollection;

    public function getConnectionStatus(RelayUrl $relay): ConnectionState;

    public function close(): void;

    public function healthCheck(): HealthCheckResultCollection;
}
