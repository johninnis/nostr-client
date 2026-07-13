<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

final class RelaySession
{
    /** @var array<string, EventHandlerInterface> */
    private array $handlers = [];

    /** @var array<string, DeferredFuture<PublishResult>> */
    private array $pendingResponses = [];

    /** @var array<string, true> */
    private array $pendingAuthEventIds = [];

    /** @var array<string, Event> */
    private array $pendingEvents = [];

    /** @var list<ParkedPublish> */
    private array $authRetryQueue = [];

    private ?WebsocketConnection $websocket;

    public function __construct(
        private RelayConnection $connection,
        WebsocketConnection $websocket,
    ) {
        $this->websocket = $websocket;
    }

    public function getConnection(): RelayConnection
    {
        return $this->connection;
    }

    public function setConnection(RelayConnection $connection): void
    {
        $this->connection = $connection;
    }

    public function send(ClientMessage $message): void
    {
        $this->getWebsocket()->sendText($message->toJson());
    }

    public function ping(): void
    {
        $this->getWebsocket()->ping();
    }

    public function closeWebsocket(): void
    {
        $this->websocket?->close();
        $this->websocket = null;
    }

    public function loseWebsocket(): void
    {
        $this->websocket = null;
    }

    private function getWebsocket(): WebsocketConnection
    {
        if (null === $this->websocket) {
            throw ConnectionException::forRelay($this->connection->getRelayUrl(), 'Websocket not available');
        }

        return $this->websocket;
    }

    public function setHandler(SubscriptionId $subscriptionId, EventHandlerInterface $handler): void
    {
        $this->handlers[(string) $subscriptionId] = $handler;
    }

    public function getHandler(SubscriptionId $subscriptionId): ?EventHandlerInterface
    {
        return $this->handlers[(string) $subscriptionId] ?? null;
    }

    public function removeHandler(SubscriptionId $subscriptionId): void
    {
        unset($this->handlers[(string) $subscriptionId]);
    }

    /**
     * @return list<EventHandlerInterface>
     */
    public function distinctHandlers(): array
    {
        $distinct = [];
        foreach ($this->handlers as $handler) {
            $distinct[spl_object_id($handler)] = $handler;
        }

        return array_values($distinct);
    }

    /**
     * @return Future<PublishResult>
     */
    public function trackPublish(string $eventIdHex): Future
    {
        $this->openPendingResponse($eventIdHex);

        return $this->pendingResponses[$eventIdHex]->getFuture();
    }

    private function openPendingResponse(string $eventIdHex): void
    {
        $deferred = new DeferredFuture();
        // Deliberate: ignore() so a dropped fire-and-forget publish future never surfaces as an unhandled error - see ADR-0009
        $deferred->getFuture()->ignore();
        $this->pendingResponses[$eventIdHex] = $deferred;
    }

    /**
     * @param DeferredFuture<PublishResult> $response
     */
    public function setPendingResponse(string $key, DeferredFuture $response): void
    {
        $this->pendingResponses[$key] = $response;
    }

    /**
     * @return DeferredFuture<PublishResult>|null
     */
    public function getPendingResponse(string $key): ?DeferredFuture
    {
        return $this->pendingResponses[$key] ?? null;
    }

    public function removePendingResponse(string $key): void
    {
        unset($this->pendingResponses[$key]);
    }

    /**
     * @return array<string, DeferredFuture<PublishResult>>
     */
    public function pendingResponses(): array
    {
        return $this->pendingResponses;
    }

    public function markPendingAuth(string $eventIdHex): void
    {
        $this->pendingAuthEventIds[$eventIdHex] = true;
    }

    public function isPendingAuth(string $eventIdHex): bool
    {
        return isset($this->pendingAuthEventIds[$eventIdHex]);
    }

    public function clearPendingAuth(string $eventIdHex): void
    {
        unset($this->pendingAuthEventIds[$eventIdHex]);
    }

    public function setPendingEvent(string $eventIdHex, Event $event): void
    {
        $this->pendingEvents[$eventIdHex] = $event;
    }

    public function getPendingEvent(string $eventIdHex): ?Event
    {
        return $this->pendingEvents[$eventIdHex] ?? null;
    }

    public function removePendingEvent(string $eventIdHex): void
    {
        unset($this->pendingEvents[$eventIdHex]);
    }

    public function parkPublish(ParkedPublish $parked): void
    {
        $this->authRetryQueue[] = $parked;
    }

    /**
     * @return list<ParkedPublish>
     */
    public function authRetryQueue(): array
    {
        return $this->authRetryQueue;
    }

    /**
     * @return list<ParkedPublish>
     */
    public function takeAuthRetryQueue(): array
    {
        $queue = $this->authRetryQueue;
        $this->authRetryQueue = [];

        return $queue;
    }
}
