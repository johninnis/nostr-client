<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Service;

use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResultCollection;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;
use function Amp\Future\awaitAll;

final class ConnectionManager implements NostrClientInterface
{
    private array $connectionTasks = [];

    public function __construct(
        private readonly ConnectionHandlerInterface $connectionHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void
    {
        $this->connectionHandler->setAuthHandler($handler);
    }

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

    public function reconnect(RelayUrl $relay): void
    {
        $connection = $this->connectionHandler->getConnection($relay);
        $config = $connection?->getConfig() ?? new ConnectionConfig();

        $this->disconnect($relay);
        $this->connect($relay, $config);
    }

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

    public function subscribeMultiple(
        RelayUrl $relay,
        array $filters,
        EventHandlerInterface $handler,
        ?SubscriptionId $subscriptionId = null,
    ): SubscriptionId {
        $this->ensureConnected($relay);
        $subscriptionId ??= SubscriptionId::generate();
        $this->connectionHandler->subscribeMultiple($relay, $subscriptionId, $filters, $handler);

        return $subscriptionId;
    }

    public function unsubscribe(RelayUrl $relay, SubscriptionId $subscriptionId): void
    {
        $this->ensureConnected($relay);
        $this->connectionHandler->unsubscribe($relay, $subscriptionId);
    }

    public function publishEvent(RelayUrl $relay, Event $event): bool
    {
        $this->ensureConnected($relay);

        return $this->connectionHandler->publishEvent($relay, $event);
    }

    public function isConnected(RelayUrl $relay): bool
    {
        $connection = $this->getConnection($relay);

        return null !== $connection && $connection->isHealthy();
    }

    public function getConnectedRelays(): RelayConnectionCollection
    {
        return $this->connectionHandler->getAllConnections()
            ->filter(static fn (RelayConnection $conn) => $conn->isHealthy());
    }

    public function getConnectionStatus(RelayUrl $relay): ConnectionState
    {
        $connection = $this->getConnection($relay);

        return $connection?->getState() ?? ConnectionState::DISCONNECTED;
    }

    public function close(): void
    {
        foreach ($this->getAllConnections() as $connection) {
            $this->disconnect($connection->getRelayUrl());
        }
    }

    public function healthCheck(): HealthCheckResultCollection
    {
        $healthTasks = [];

        foreach ($this->connectionHandler->getAllConnections() as $connection) {
            $relayUrl = $connection->getRelayUrl();
            $healthTasks[(string) $relayUrl] = async(function () use ($relayUrl) {
                $startTime = microtime(true);
                try {
                    $this->ping($relayUrl);
                    $latencyMs = (microtime(true) - $startTime) * 1000;

                    return HealthCheckResult::success($latencyMs);
                } catch (Throwable $e) {
                    return HealthCheckResult::failure($e->getMessage());
                }
            });
        }

        [, $results] = awaitAll($healthTasks);

        return new HealthCheckResultCollection($results);
    }

    public function getConnection(RelayUrl $relay): ?RelayConnection
    {
        return $this->connectionHandler->getConnection($relay);
    }

    public function getAllConnections(): RelayConnectionCollection
    {
        return $this->connectionHandler->getAllConnections();
    }

    public function ping(RelayUrl $relay): bool
    {
        $this->ensureConnected($relay);

        return $this->connectionHandler->ping($relay);
    }

    private function ensureConnected(RelayUrl $relay): void
    {
        if (!$this->isConnected($relay)) {
            throw ConnectionException::forRelay($relay, 'Not connected - use connect() first');
        }
    }

    private function unsubscribeAll(RelayUrl $relay, RelayConnection $connection): void
    {
        foreach ($connection->getSubscriptions() as $subscriptionIdString => $_) {
            try {
                $this->connectionHandler->unsubscribe($relay, SubscriptionId::fromString($subscriptionIdString));
            } catch (Throwable $e) {
                $this->logger->warning('Failed to unsubscribe during disconnect', [
                    'relay' => (string) $relay,
                    'subscription_id' => $subscriptionIdString,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
