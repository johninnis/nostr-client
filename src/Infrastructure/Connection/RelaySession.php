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

/**
 * The complete live state of one relay connection: the immutable connection
 * snapshot, the socket, and the per-subscription and per-publish bookkeeping that
 * was previously spread across parallel maps keyed by "url:subId" / "url:eventId".
 * Holding it per relay removes those composite keys and the prefix scans over them.
 */
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

    // Nullable because a FAILED connection's state outlives its socket: the socket
    // is lost on a connection error while the connection stays observable as FAILED
    // until the reconnect loop replaces it or gives up.
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

    public function getWebsocket(): WebsocketConnection
    {
        if (null === $this->websocket) {
            throw ConnectionException::forRelay($this->connection->getRelayUrl(), 'Websocket not available');
        }

        return $this->websocket;
    }

    public function send(ClientMessage $message): void
    {
        $this->getWebsocket()->sendText($message->toJson());
    }

    public function loseWebsocket(): void
    {
        $this->websocket = null;
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

        // Read back across the method boundary so the future carries the array's declared
        // DeferredFuture<PublishResult> type rather than the unparameterised new DeferredFuture.
        return $this->pendingResponses[$eventIdHex]->getFuture();
    }

    private function openPendingResponse(string $eventIdHex): void
    {
        $deferred = new DeferredFuture();
        // Ignored so a fire-and-forget publish whose future the caller drops does not
        // surface as an unhandled error; awaiting the returned future still yields the outcome.
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
