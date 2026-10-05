<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Amp\Future;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\ConnectResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

interface ConnectionHandlerInterface
{
    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void;

    public function setReconnectionListener(ReconnectionListenerInterface $listener): void;

    public function setAuthResultListener(AuthResultListenerInterface $listener): void;

    public function connect(RelayUrl $relayUrl, ConnectionConfig $config): ConnectResult;

    public function disconnect(RelayUrl $relayUrl): void;

    public function subscribe(SubscriptionRequest $request, ?EventHandlerInterface $handler = null): void;

    public function unsubscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId): void;

    /**
     * @return Future<PublishResult>
     */
    public function publishEvent(RelayUrl $relayUrl, Event $event): Future;

    public function awaitPendingPublishes(RelayUrl $relayUrl, ?float $timeoutSeconds = null): void;

    public function ping(RelayUrl $relayUrl): HealthCheckResult;

    public function isConnected(RelayUrl $relayUrl): bool;

    public function getConnection(RelayUrl $relayUrl): ?RelayConnection;

    public function getAllConnections(): RelayConnectionCollection;
}
