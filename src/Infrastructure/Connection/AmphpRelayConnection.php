<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Pipeline\Queue;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\AuthResultListenerInterface;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\ConnectResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;
use function Amp\weakClosure;

final class AmphpRelayConnection implements ConnectionHandlerInterface
{
    private const int MAX_INBOUND_BACKLOG = 1024;
    private const string HEARTBEAT_SUBSCRIPTION_ID = 'keepalive';

    // Deliberate: the optional reconnection listener is registered after construction, not constructor-injected - see ADR-0010
    private ?ReconnectionListenerInterface $reconnectionListener = null;
    private readonly RelaySessionRegistry $registry;
    private readonly InboundMessageDispatcher $dispatcher;
    private readonly ConnectionErrorHandler $errorHandler;
    private readonly AuthMessageHandler $authMessageHandler;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->registry = new RelaySessionRegistry();
        $this->errorHandler = new ConnectionErrorHandler($this->registry, $this->logger);
        $this->authMessageHandler = new AuthMessageHandler($this->logger);
        $this->dispatcher = new InboundMessageDispatcher($this->logger, new InboundMessageHandlers(
            new EventMessageHandler(),
            new OkMessageHandler($this->authMessageHandler),
            new EoseMessageHandler(),
            new ClosedMessageHandler($this->authMessageHandler),
            new NoticeMessageHandler($this->logger),
            $this->authMessageHandler,
        ));
    }

    // Deliberate: the auth handler is registered after construction, not constructor-injected - see ADR-0010
    #[Override]
    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->authMessageHandler->setAuthHandler($handler);
    }

    #[Override]
    public function setReconnectionListener(ReconnectionListenerInterface $listener): void
    {
        $this->reconnectionListener = $listener;
    }

    #[Override]
    public function setAuthResultListener(AuthResultListenerInterface $listener): void
    {
        $this->authMessageHandler->setAuthResultListener($listener);
    }

    #[Override]
    public function connect(RelayUrl $relayUrl, ConnectionConfig $config): ConnectResult
    {
        $existing = $this->registry->find($relayUrl);
        if (null !== $existing && $existing->getConnection()->isHealthy()) {
            return ConnectResult::connected();
        }

        try {
            $websocket = $this->connectionFactory->createConnection($relayUrl, $config);
        } catch (ConnectionException $e) {
            $this->logger->warning('Relay could not be reached', [
                'relay' => (string) $relayUrl,
                'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ]);

            return ConnectResult::failedToConnect();
        }

        $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, $config);
        $this->registry->store($relayUrl, new RelaySession($connection, $websocket));

        $generation = $this->registry->nextGeneration($relayUrl);

        $this->startMessageHandler($relayUrl, $websocket, $generation);
        $this->startHeartbeat($relayUrl, $generation, $config);

        return ConnectResult::connected();
    }

    #[Override]
    public function disconnect(RelayUrl $relayUrl): void
    {
        $urlString = (string) $relayUrl;

        $this->registry->cancelReconnect($relayUrl);
        $this->registry->cancelHeartbeat($relayUrl);

        $session = $this->registry->find($relayUrl);
        if (null === $session) {
            return;
        }

        $session->settleAllPublishes(PublishResult::unavailable(RelayUnavailability::Disconnected));

        if (ConnectionState::CONNECTED === $session->getConnection()->getState()) {
            $session->setConnection($session->getConnection()->withState(ConnectionState::DISCONNECTING));
        }

        // Deliberate: bumping the generation on disconnect fences the outgoing message loop - see ADR-0008
        $this->registry->nextGeneration($relayUrl);

        try {
            $session->closeWebsocket();
        } catch (Throwable $e) {
            $this->logger->debug('Failed to close WebSocket during disconnect', [
                'relay' => $urlString,
                'error' => $e->getMessage(),
            ]);
        }

        $this->registry->remove($relayUrl);
    }

    #[Override]
    public function subscribe(SubscriptionRequest $request, ?EventHandlerInterface $handler = null): void
    {
        $relayUrl = $request->getRelay();
        $subscriptionId = $request->getSubscriptionId() ?? SubscriptionId::generate();

        // Deliberate: a subscription no filter of which can match is answered with an end of stored events and never sent — see ADR-0018
        $matchable = $request->getFilters()->matchable();

        if ($matchable->isEmpty()) {
            $handler?->handleEose($subscriptionId);

            return;
        }

        $session = $this->liveSession($relayUrl);

        if (null === $session) {
            $handler?->handleClosed($subscriptionId, RelayUnavailability::Disconnected->value);

            return;
        }

        $session->setConnection($session->getConnection()->withSubscription($subscriptionId, $matchable));

        if (null !== $handler) {
            $session->setHandler($subscriptionId, $handler);
        }

        try {
            $session->send(ReqMessage::from($subscriptionId, $matchable));
            $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Active));
        } catch (Throwable $e) {
            $this->handleConnectionError($relayUrl, $e);
        }
    }

    #[Override]
    public function unsubscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId): void
    {
        $session = $this->liveSession($relayUrl);

        if (null === $session) {
            return;
        }

        try {
            $session->setConnection($session->getConnection()->withoutSubscription($subscriptionId));
            $session->removeHandler($subscriptionId);

            $session->send(new CloseMessage($subscriptionId));
        } catch (Throwable $e) {
            $this->logger->warning('Failed to unsubscribe', [
                'relay' => (string) $relayUrl,
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Future<PublishResult>
     */
    #[Override]
    public function publishEvent(RelayUrl $relayUrl, Event $event): Future
    {
        $session = $this->liveSession($relayUrl);

        if (null === $session) {
            return Future::complete(PublishResult::unavailable(RelayUnavailability::Disconnected));
        }

        $eventIdHex = $event->getId()->toHex();

        $inFlight = $session->getPendingResponse($eventIdHex);
        if (null !== $inFlight) {
            return $inFlight->getFuture();
        }

        $session->setPendingEvent($eventIdHex, $event);
        $future = $session->trackPublish($eventIdHex);

        try {
            $session->send(new EventMessage($event));
        } catch (Throwable $e) {
            $this->handleConnectionError($relayUrl, $e);
        }

        return $future;
    }

    #[Override]
    public function awaitPendingPublishes(RelayUrl $relayUrl, ?float $timeoutSeconds = null): void
    {
        $session = $this->registry->find($relayUrl);
        if (null === $session) {
            return;
        }

        $futures = [];
        foreach ($session->pendingResponses() as $deferred) {
            $futures[] = $deferred->getFuture();
        }

        if ([] === $futures) {
            return;
        }

        $cancellation = null !== $timeoutSeconds ? new TimeoutCancellation($timeoutSeconds) : null;

        try {
            awaitAll($futures, $cancellation);
        } catch (CancelledException) {
        }
    }

    #[Override]
    public function ping(RelayUrl $relayUrl): HealthCheckResult
    {
        $session = $this->liveSession($relayUrl);

        if (null === $session) {
            return HealthCheckResult::failure($relayUrl, RelayUnavailability::Disconnected->value);
        }

        try {
            $session->ping();
        } catch (ConnectionException|StreamException $e) {
            $this->logger->debug('Relay ping failed', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);

            return HealthCheckResult::failure($relayUrl, RelayUnavailability::Disconnected->value);
        }

        return HealthCheckResult::success($relayUrl);
    }

    #[Override]
    public function isConnected(RelayUrl $relayUrl): bool
    {
        $connection = $this->getConnection($relayUrl);

        return null !== $connection && $connection->isHealthy();
    }

    #[Override]
    public function getConnection(RelayUrl $relayUrl): ?RelayConnection
    {
        return $this->registry->find($relayUrl)?->getConnection();
    }

    #[Override]
    public function getAllConnections(): RelayConnectionCollection
    {
        return new RelayConnectionCollection(array_map(
            static fn (RelaySession $session): RelayConnection => $session->getConnection(),
            $this->registry->all(),
        ));
    }

    private function liveSession(RelayUrl $relayUrl): ?RelaySession
    {
        $session = $this->registry->find($relayUrl);

        return null !== $session && $session->getConnection()->isHealthy() ? $session : null;
    }

    private function startMessageHandler(RelayUrl $relayUrl, WebsocketConnection $websocket, int $generation): void
    {
        /** @var Queue<string> $inbound */
        $inbound = new Queue(self::MAX_INBOUND_BACKLOG);

        // Deliberate: one ordered dispatch fiber drains the queue so a slow handler blocks only itself, never the socket reader - see ADR-0017
        async(weakClosure(function () use ($relayUrl, $inbound): void {
            foreach ($inbound->iterate() as $payload) {
                $this->handleMessage($relayUrl, $payload);
            }
        }))->ignore();

        $task = async(weakClosure(function () use ($relayUrl, $websocket, $generation, $inbound) {
            try {
                foreach ($websocket as $message) {
                    // Deliberate: a full buffer means the consumer is MAX_INBOUND_BACKLOG behind - fail the connection rather than grow unbounded or stall the reader - see ADR-0017
                    $accepted = $inbound->pushAsync($message->buffer());

                    if (!$accepted->isComplete()) {
                        $accepted->ignore();

                        throw new RuntimeException('Inbound message backlog exceeded; consumer too slow');
                    }
                }

                if ($this->registry->generation($relayUrl) === $generation) {
                    $this->handleConnectionError(
                        $relayUrl,
                        new RuntimeException('WebSocket closed by remote'),
                        $generation
                    );
                }
            } catch (Throwable $e) {
                if ($this->registry->generation($relayUrl) !== $generation) {
                    return;
                }
                $this->handleConnectionError($relayUrl, $e, $generation);
            } finally {
                $inbound->complete();
            }
        }));

        $task->catch(weakClosure(function (Throwable $e) use ($relayUrl, $generation) {
            try {
                if ($this->registry->generation($relayUrl) === $generation) {
                    $this->handleConnectionError($relayUrl, $e, $generation);
                }
            } catch (Throwable $e) {
                $this->logger->debug('Failed to handle connection error', [
                    'relay' => (string) $relayUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }))->ignore();
    }

    // Deliberate: periodic CLOSE keep-alive for an otherwise-silent connection, generation-fenced like the message loop - see ADR-0013
    private function startHeartbeat(RelayUrl $relayUrl, int $generation, ConnectionConfig $config): void
    {
        $intervalMs = $config->getHeartbeatIntervalMs();

        if (0 === $intervalMs) {
            return;
        }

        $intervalSeconds = $intervalMs / 1000.0;
        $keepAlive = SubscriptionId::tryFromString(self::HEARTBEAT_SUBSCRIPTION_ID);

        if (null === $keepAlive) {
            return;
        }

        $deferred = new DeferredCancellation();
        $this->registry->beginHeartbeat($relayUrl, $deferred);
        $cancellation = $deferred->getCancellation();

        async(weakClosure(function () use ($relayUrl, $generation, $intervalSeconds, $keepAlive, $cancellation): void {
            while (true) {
                try {
                    delay($intervalSeconds, cancellation: $cancellation);
                } catch (CancelledException) {
                    return;
                }

                if ($this->registry->generation($relayUrl) !== $generation) {
                    return;
                }

                $session = $this->registry->find($relayUrl);

                if (null === $session || !$session->getConnection()->isHealthy()) {
                    return;
                }

                try {
                    $session->send(new CloseMessage($keepAlive));
                } catch (Throwable $e) {
                    $this->logger->debug('Heartbeat send failed', [
                        'relay' => (string) $relayUrl,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }
            }
        }))->ignore();
    }

    private function handleMessage(RelayUrl $relayUrl, string $jsonMessage): void
    {
        $session = $this->registry->find($relayUrl);

        if (null === $session) {
            return;
        }

        $this->dispatcher->dispatch($session, $jsonMessage);
    }

    private function handleConnectionError(RelayUrl $relayUrl, Throwable $error, ?int $generation = null): void
    {
        $reconnectConfig = $this->errorHandler->fail($relayUrl, $error, $generation);

        if (null !== $reconnectConfig) {
            $this->scheduleReconnect($relayUrl, $reconnectConfig);
        }
    }

    private function scheduleReconnect(RelayUrl $relayUrl, ConnectionConfig $config): void
    {
        $urlString = (string) $relayUrl;

        if ($this->registry->hasReconnect($relayUrl)) {
            return;
        }

        $deferred = new DeferredCancellation();
        $this->registry->beginReconnect($relayUrl, $deferred);
        $cancellation = $deferred->getCancellation();

        async(weakClosure(function () use ($relayUrl, $config, $cancellation, $urlString): void {
            $maxAttempts = $config->getReconnectMaxAttempts();
            $attempt = 0;

            while (0 === $maxAttempts || $attempt < $maxAttempts) {
                $delayMs = $config->baseBackoffMs($attempt);
                $jitterMs = random_int(0, (int) ($delayMs * 0.25));
                $totalSeconds = ($delayMs + $jitterMs) / 1000.0;

                try {
                    delay($totalSeconds, cancellation: $cancellation);
                } catch (CancelledException) {
                    return;
                }

                ++$attempt;

                $this->logger->info('Attempting relay reconnect', [
                    'relay' => $urlString,
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs + $jitterMs,
                ]);

                if (!$this->connect($relayUrl, $config)->isConnected()) {
                    $this->logger->warning('Relay reconnect attempt failed', [
                        'relay' => $urlString,
                        'attempt' => $attempt,
                    ]);
                    continue;
                }

                if (!$this->registry->hasReconnect($relayUrl)) {
                    $this->disconnect($relayUrl);

                    return;
                }

                $this->registry->endReconnect($relayUrl);

                $this->logger->info('Relay reconnect succeeded', [
                    'relay' => $urlString,
                    'attempt' => $attempt,
                ]);

                if (null !== $this->reconnectionListener) {
                    try {
                        $this->reconnectionListener->onReconnected($relayUrl);
                    } catch (Throwable $e) {
                        $this->logger->error('onReconnected listener threw', [
                            'relay' => $urlString,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return;
            }

            $this->registry->endReconnect($relayUrl);

            $this->logger->error('Relay reconnect gave up after max attempts', [
                'relay' => $urlString,
                'attempts' => $attempt,
            ]);
        }))->ignore();
    }
}
