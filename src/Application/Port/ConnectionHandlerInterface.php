<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

interface ConnectionHandlerInterface
{
    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void;

    public function sendAuth(RelayUrl $relayUrl, Event $signedAuthEvent): void;

    public function connect(RelayUrl $relayUrl, ConnectionConfig $config): void;

    public function disconnect(RelayUrl $relayUrl): void;

    public function subscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId, Filter $filter, ?EventHandlerInterface $handler = null): void;

    public function subscribeMultiple(RelayUrl $relayUrl, SubscriptionId $subscriptionId, array $filters, ?EventHandlerInterface $handler = null): void;

    public function unsubscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId): void;

    public function publishEvent(RelayUrl $relayUrl, Event $event): bool;

    public function ping(RelayUrl $relayUrl): bool;

    public function isConnected(RelayUrl $relayUrl): bool;

    public function getConnection(RelayUrl $relayUrl): ?RelayConnection;

    public function getAllConnections(): RelayConnectionCollection;
}
