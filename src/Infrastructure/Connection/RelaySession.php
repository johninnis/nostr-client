<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;
use Closure;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\ClientMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

use function Amp\async;
use function Amp\delay;

final class RelaySession
{
    /** @var array<string, EventHandlerInterface> */
    private array $handlers = [];

    /** @var array<string, DeferredFuture<PublishResult>> */
    private array $pendingResponses = [];

    /** @var array<string, Event> */
    private array $pendingEvents = [];

    private ?Challenge $challenge = null;
    private ?Challenge $answeredChallenge = null;
    private ?string $authEventIdHex = null;
    private bool $authenticated = false;
    private bool $authDeclined = false;
    private ?DeferredCancellation $authTimeout = null;

    /** @var array<string, DeferredCancellation> */
    private array $publishTimeouts = [];

    /** @var list<ParkedWorkInterface> */
    private array $parked = [];

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
        $this->cancelAuthTimeout();
        $this->websocket?->close();
        $this->websocket = null;
    }

    public function loseWebsocket(): void
    {
        $this->cancelAuthTimeout();
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
        $this->startPublishTimeout($eventIdHex);

        return $this->pendingResponses[$eventIdHex]->getFuture();
    }

    // Deliberate: a relay that never answers a publish settles it as timeout, so no publish waits forever - see ADR-0015
    public function startPublishTimeout(string $eventIdHex): void
    {
        $this->suspendPublishTimeout($eventIdHex);

        $deferred = new DeferredCancellation();
        $this->publishTimeouts[$eventIdHex] = $deferred;
        $seconds = $this->connection->getConfig()->getPublishTimeoutMs() / 1000.0;

        async(function () use ($deferred, $seconds, $eventIdHex): void {
            try {
                delay($seconds, cancellation: $deferred->getCancellation());
            } catch (CancelledException) {
                return;
            }

            unset($this->publishTimeouts[$eventIdHex]);
            $this->settlePublish($eventIdHex, PublishResult::unavailable(RelayUnavailability::Timeout));
        })->ignore();
    }

    public function suspendPublishTimeout(string $eventIdHex): void
    {
        ($this->publishTimeouts[$eventIdHex] ?? null)?->cancel();
        unset($this->publishTimeouts[$eventIdHex]);
    }

    private function openPendingResponse(string $eventIdHex): void
    {
        /** @var DeferredFuture<PublishResult> $deferred */
        $deferred = new DeferredFuture();
        // Deliberate: ignore() so a dropped fire-and-forget publish future never surfaces as an unhandled error - see ADR-0015
        $deferred->getFuture()->ignore();
        $this->pendingResponses[$eventIdHex] = $deferred;
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
        $this->suspendPublishTimeout($key);
        unset($this->pendingResponses[$key]);
    }

    /**
     * @return array<string, DeferredFuture<PublishResult>>
     */
    public function pendingResponses(): array
    {
        return $this->pendingResponses;
    }

    public function settlePublish(string $eventIdHex, PublishResult $result): void
    {
        $this->suspendPublishTimeout($eventIdHex);
        $deferred = $this->pendingResponses[$eventIdHex] ?? null;
        unset($this->pendingResponses[$eventIdHex], $this->pendingEvents[$eventIdHex]);
        $deferred?->complete($result);
    }

    public function settleAllPublishes(PublishResult $result): void
    {
        foreach (array_keys($this->pendingResponses) as $eventIdHex) {
            $this->settlePublish($eventIdHex, $result);
        }
    }

    public function closeSubscription(SubscriptionId $subscriptionId, string $reason): void
    {
        if (!$this->connection->hasSubscription($subscriptionId)) {
            return;
        }

        $handler = $this->getHandler($subscriptionId);
        $this->connection = $this->connection->withoutSubscription($subscriptionId);
        $this->removeHandler($subscriptionId);

        $handler?->handleClosed($subscriptionId, $reason);
    }

    public function storeChallenge(Challenge $challenge): void
    {
        $this->challenge = $challenge;
        $this->authDeclined = false;
    }

    public function getChallenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function hasUnansweredChallenge(): bool
    {
        return null !== $this->challenge && $this->challenge !== $this->answeredChallenge;
    }

    public function markChallengeAnswered(): void
    {
        $this->answeredChallenge = $this->challenge;
    }

    public function beginAuthAttempt(string $eventIdHex): void
    {
        $this->authEventIdHex = $eventIdHex;
    }

    public function isAuthAttempt(string $eventIdHex): bool
    {
        return $this->authEventIdHex === $eventIdHex;
    }

    public function hasAuthAttempt(): bool
    {
        return null !== $this->authEventIdHex;
    }

    public function endAuthAttempt(): void
    {
        $this->authEventIdHex = null;
    }

    public function markAuthenticated(): void
    {
        $this->authenticated = true;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function declineAuth(): void
    {
        $this->authDeclined = true;
    }

    public function isAuthDeclined(): bool
    {
        return $this->authDeclined;
    }

    public function startAuthTimeout(Closure $onExpiry): void
    {
        $this->cancelAuthTimeout();

        $deferred = new DeferredCancellation();
        $this->authTimeout = $deferred;
        $seconds = $this->connection->getConfig()->getAuthTimeoutMs() / 1000.0;

        async(function () use ($deferred, $seconds, $onExpiry): void {
            try {
                delay($seconds, cancellation: $deferred->getCancellation());
            } catch (CancelledException) {
                return;
            }

            $this->authTimeout = null;
            $onExpiry();
        })->ignore();
    }

    public function hasAuthTimeout(): bool
    {
        return null !== $this->authTimeout;
    }

    public function cancelAuthTimeout(): void
    {
        $this->authTimeout?->cancel();
        $this->authTimeout = null;
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

    public function park(ParkedWorkInterface $work): void
    {
        $this->parked[] = $work;
    }

    public function hasParked(): bool
    {
        return [] !== $this->parked;
    }

    /**
     * @return list<ParkedWorkInterface>
     */
    public function takeParked(): array
    {
        $parked = $this->parked;
        $this->parked = [];

        return $parked;
    }
}
