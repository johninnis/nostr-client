<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Service;

use Amp\Future;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\AuthResultListenerInterface;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\Collection\HealthCheckResultCollection;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\ConnectResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

final class MultiRelayNostrClient implements NostrClientInterface
{
    /** @var array<string, Future<ConnectResult>> */
    private array $connectionTasks = [];

    public function __construct(
        private readonly ConnectionHandlerInterface $connectionHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->connectionHandler->setAuthHandler($handler);
    }

    #[Override]
    public function setReconnectionListener(ReconnectionListenerInterface $listener): void
    {
        $this->connectionHandler->setReconnectionListener($listener);
    }

    #[Override]
    public function setAuthResultListener(AuthResultListenerInterface $listener): void
    {
        $this->connectionHandler->setAuthResultListener($listener);
    }

    #[Override]
    public function connect(RelayUrl $relay, ?ConnectionConfig $config = null): ConnectResult
    {
        $config ??= new ConnectionConfig();
        $urlString = (string) $relay;

        if ($this->connectionHandler->isConnected($relay)) {
            return ConnectResult::connected();
        }

        if (isset($this->connectionTasks[$urlString])) {
            return $this->connectionTasks[$urlString]->await();
        }

        /** @var Future<ConnectResult> $connecting */
        $connecting = async(function () use ($relay, $config): ConnectResult {
            try {
                return $this->connectionHandler->connect($relay, $config);
            } finally {
                unset($this->connectionTasks[(string) $relay]);
            }
        });
        $this->connectionTasks[$urlString] = $connecting;

        $result = $connecting->await();

        if (!$result->isConnected()) {
            $this->logger->warning('Failed to connect to relay', [
                'relay' => $urlString,
                'reason' => $result->getMessage(),
            ]);
        }

        return $result;
    }

    #[Override]
    public function disconnect(RelayUrl $relay): void
    {
        $urlString = (string) $relay;

        if (isset($this->connectionTasks[$urlString])) {
            $this->connectionTasks[$urlString]->ignore();
            unset($this->connectionTasks[$urlString]);
        }

        $connection = $this->connectionHandler->getConnection($relay);

        if (null !== $connection) {
            $this->unsubscribeAll($relay, $connection);
            $this->connectionHandler->disconnect($relay);
        }
    }

    #[Override]
    public function reconnect(RelayUrl $relay): ConnectResult
    {
        $connection = $this->connectionHandler->getConnection($relay);
        $config = $connection?->getConfig() ?? new ConnectionConfig();

        $this->disconnect($relay);

        return $this->connect($relay, $config);
    }

    #[Override]
    public function subscribe(SubscriptionRequest $request, EventHandlerInterface $handler): SubscriptionId
    {
        $subscriptionId = $request->getSubscriptionId() ?? SubscriptionId::generate();
        $request = $request->withSubscriptionId($subscriptionId);

        if (!$this->isConnected($request->getRelay())) {
            $handler->handleClosed($subscriptionId, RelayUnavailability::Disconnected->value);

            return $subscriptionId;
        }

        $this->connectionHandler->subscribe($request, $handler);

        return $subscriptionId;
    }

    #[Override]
    public function unsubscribe(RelayUrl $relay, SubscriptionId $subscriptionId): void
    {
        if (!$this->isConnected($relay)) {
            return;
        }

        $this->connectionHandler->unsubscribe($relay, $subscriptionId);
    }

    /**
     * @return Future<PublishResult>
     */
    #[Override]
    public function publishEvent(RelayUrl $relay, Event $event): Future
    {
        if (!$this->isConnected($relay)) {
            return Future::complete(PublishResult::unavailable(RelayUnavailability::Disconnected));
        }

        return $this->connectionHandler->publishEvent($relay, $event);
    }

    #[Override]
    public function awaitPendingPublishes(RelayUrl $relay, ?float $timeoutSeconds = null): void
    {
        $this->connectionHandler->awaitPendingPublishes($relay, $timeoutSeconds);
    }

    #[Override]
    public function isConnected(RelayUrl $relay): bool
    {
        return $this->connectionHandler->isConnected($relay);
    }

    #[Override]
    public function getConnectedRelays(): RelayConnectionCollection
    {
        return $this->connectionHandler->getAllConnections()
            ->filter(static fn (RelayConnection $conn) => $conn->isHealthy());
    }

    #[Override]
    public function getConnectionStatus(RelayUrl $relay): ConnectionState
    {
        $connection = $this->getConnection($relay);

        return $connection?->getState() ?? ConnectionState::DISCONNECTED;
    }

    #[Override]
    public function close(): void
    {
        foreach ($this->getAllConnections() as $connection) {
            $this->disconnect($connection->getRelayUrl());
        }
    }

    #[Override]
    public function healthCheck(): HealthCheckResultCollection
    {
        $healthTasks = [];

        foreach ($this->connectionHandler->getAllConnections() as $connection) {
            $relayUrl = $connection->getRelayUrl();
            $healthTasks[] = async(fn (): HealthCheckResult => $this->ping($relayUrl));
        }

        [, $results] = awaitAll($healthTasks);

        return new HealthCheckResultCollection($results);
    }

    #[Override]
    public function getConnection(RelayUrl $relay): ?RelayConnection
    {
        return $this->connectionHandler->getConnection($relay);
    }

    #[Override]
    public function getAllConnections(): RelayConnectionCollection
    {
        return $this->connectionHandler->getAllConnections();
    }

    #[Override]
    public function ping(RelayUrl $relay): HealthCheckResult
    {
        if (!$this->isConnected($relay)) {
            return HealthCheckResult::failure($relay, RelayUnavailability::Disconnected->value);
        }

        return $this->connectionHandler->ping($relay);
    }

    private function unsubscribeAll(RelayUrl $relay, RelayConnection $connection): void
    {
        foreach ($connection->getSubscriptions() as $subscription) {
            $subscriptionId = $subscription->getId();
            try {
                $this->connectionHandler->unsubscribe($relay, $subscriptionId);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to unsubscribe during disconnect', [
                    'relay' => (string) $relay,
                    'subscription_id' => (string) $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
