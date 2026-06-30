<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\Future;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Collection\HealthCheckResultCollection;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\Service\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

final class ConnectionManager implements NostrClientInterface
{
    /** @var array<string, Future<void>> */
    private array $connectionTasks = [];

    public function __construct(
        private readonly ConnectionHandlerInterface $connectionHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void
    {
        $this->connectionHandler->setAuthHandler($handler);
    }

    #[Override]
    public function setReconnectionListener(ReconnectionListenerInterface $listener): void
    {
        $this->connectionHandler->setReconnectionListener($listener);
    }

    #[Override]
    public function connect(RelayUrl $relay, ?ConnectionConfig $config = null): void
    {
        $config ??= new ConnectionConfig();
        $urlString = (string) $relay;

        if ($this->connectionHandler->isConnected($relay)) {
            return;
        }

        if (isset($this->connectionTasks[$urlString])) {
            $this->connectionTasks[$urlString]->await();

            return;
        }

        $this->connectionTasks[$urlString] = async(function () use ($relay, $config) {
            try {
                $this->connectionHandler->connect($relay, $config);
            } finally {
                unset($this->connectionTasks[(string) $relay]);
            }
        });

        try {
            $this->connectionTasks[$urlString]->await();
        } catch (Throwable $e) {
            $this->logger->error('Failed to connect to relay', [
                'relay' => (string) $relay,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
    public function reconnect(RelayUrl $relay): void
    {
        $connection = $this->connectionHandler->getConnection($relay);
        $config = $connection?->getConfig() ?? new ConnectionConfig();

        $this->disconnect($relay);
        $this->connect($relay, $config);
    }

    #[Override]
    public function subscribe(
        RelayUrl $relay,
        Filter $filter,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId {
        $this->ensureConnected($relay);
        $subscriptionId ??= SubscriptionId::generate();
        $this->connectionHandler->subscribe($relay, $subscriptionId, $filter, $handler);

        return $subscriptionId;
    }

    #[Override]
    public function subscribeMultiple(
        RelayUrl $relay,
        FilterCollection $filters,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId {
        $this->ensureConnected($relay);
        $subscriptionId ??= SubscriptionId::generate();
        $this->connectionHandler->subscribeMultiple($relay, $subscriptionId, $filters, $handler);

        return $subscriptionId;
    }

    #[Override]
    public function unsubscribe(RelayUrl $relay, SubscriptionId $subscriptionId): void
    {
        $this->ensureConnected($relay);
        $this->connectionHandler->unsubscribe($relay, $subscriptionId);
    }

    #[Override]
    public function publishEvent(RelayUrl $relay, Event $event): void
    {
        $this->ensureConnected($relay);

        $this->connectionHandler->publishEvent($relay, $event);
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
            $healthTasks[] = async(function () use ($relayUrl) {
                $startTime = microtime(true);
                try {
                    $this->ping($relayUrl);
                    $latencyMs = (microtime(true) - $startTime) * 1000;

                    return HealthCheckResult::success($relayUrl, $latencyMs);
                } catch (Throwable $e) {
                    return HealthCheckResult::failure($relayUrl, $e->getMessage());
                }
            });
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
    public function ping(RelayUrl $relay): void
    {
        $this->ensureConnected($relay);

        $this->connectionHandler->ping($relay);
    }

    private function ensureConnected(RelayUrl $relay): void
    {
        if (!$this->isConnected($relay)) {
            throw ConnectionException::forRelay($relay, 'Not connected - use connect() first');
        }
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
