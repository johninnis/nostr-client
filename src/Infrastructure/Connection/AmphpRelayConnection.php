<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage as ClientAuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage as RelayEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;
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
    private const string APPLICATION_PING_NOTICE = 'ping';
    private const string KEEP_ALIVE_SUBSCRIPTION_ID = 'keepalive';

    // Deliberate: optional observer hooks registered after construction, not constructor-injected - see ADR-0010
    private ?AuthChallengeHandlerInterface $authHandler = null;
    private ?ReconnectionListenerInterface $reconnectionListener = null;
    /** @var array<string, RelaySession> */
    private array $sessions = [];
    // Kept apart from the session: a monotonic per-relay counter that must outlive
    // any single connection so a superseded message loop can be fenced - see ADR-0008.
    /** @var array<string, int> */
    private array $connectionGenerations = [];
    // Kept apart from the session: it exists during the reconnect window, when there
    // is deliberately no session for the relay.
    /** @var array<string, DeferredCancellation> */
    private array $reconnectCancellations = [];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly MessageDeserialiserInterface $deserialiser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    #[Override]
    public function setReconnectionListener(ReconnectionListenerInterface $listener): void
    {
        $this->reconnectionListener = $listener;
    }

    #[Override]
    public function sendAuth(RelayUrl $relayUrl, Event $signedAuthEvent): void
    {
        $eventIdHex = $signedAuthEvent->getId()->toHex();
        $session = $this->requireSession($relayUrl);
        $websocket = $session->getWebsocket();
        $session->markPendingAuth($eventIdHex);

        try {
            $websocket->sendText(new ClientAuthMessage($signedAuthEvent)->toJson());
        } catch (Throwable $e) {
            $session->clearPendingAuth($eventIdHex);

            throw ConnectionException::forRelay($relayUrl, $e->getMessage(), $e);
        }
    }

    #[Override]
    public function connect(RelayUrl $relayUrl, ConnectionConfig $config): void
    {
        $urlString = (string) $relayUrl;

        $existing = $this->sessions[$urlString] ?? null;
        if (null !== $existing && $existing->getConnection()->isHealthy()) {
            return;
        }

        try {
            $websocket = $this->connectionFactory->createConnection($relayUrl, $config);

            $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, $config);
            $this->sessions[$urlString] = new RelaySession($connection, $websocket);

            $generation = ($this->connectionGenerations[$urlString] ?? 0) + 1;
            $this->connectionGenerations[$urlString] = $generation;

            $this->startMessageHandler($relayUrl, $websocket, $generation);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ConnectionException::forRelay($relayUrl, $e->getMessage(), $e);
        }
    }

    #[Override]
    public function disconnect(RelayUrl $relayUrl): void
    {
        $urlString = (string) $relayUrl;

        if (isset($this->reconnectCancellations[$urlString])) {
            $this->reconnectCancellations[$urlString]->cancel();
            unset($this->reconnectCancellations[$urlString]);
        }

        $session = $this->sessions[$urlString] ?? null;
        if (null === $session) {
            return;
        }

        if (ConnectionState::CONNECTED === $session->getConnection()->getState()) {
            $session->setConnection($session->getConnection()->withState(ConnectionState::DISCONNECTING));
        }

        // Deliberate: bumping the generation on disconnect fences the outgoing message loop - see ADR-0008
        $this->connectionGenerations[$urlString] = ($this->connectionGenerations[$urlString] ?? 0) + 1;

        try {
            $session->getWebsocket()->close();
        } catch (Throwable $e) {
            $this->logger->debug('Failed to close WebSocket during disconnect', [
                'relay' => $urlString,
                'error' => $e->getMessage(),
            ]);
        }

        // Dropping the session releases its handlers, pending responses, events and auth queue.
        unset($this->sessions[$urlString]);
    }

    #[Override]
    public function subscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId, Filter $filter, ?EventHandlerInterface $handler = null): void
    {
        $this->subscribeMultiple($relayUrl, $subscriptionId, new FilterCollection([$filter]), $handler);
    }

    #[Override]
    public function subscribeMultiple(RelayUrl $relayUrl, SubscriptionId $subscriptionId, FilterCollection $filters, ?EventHandlerInterface $handler = null): void
    {
        $session = $this->requireSession($relayUrl);
        $session->setConnection($session->getConnection()->withSubscription($subscriptionId, $filters));

        if (null !== $handler) {
            $session->setHandler($subscriptionId, $handler);
        }

        try {
            $session->getWebsocket()->sendText(new ReqMessage($subscriptionId, $filters)->toJson());
            $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Active));
        } catch (Throwable $e) {
            $this->handleConnectionError($relayUrl, $e);
        }
    }

    #[Override]
    public function unsubscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId): void
    {
        try {
            if (!$this->isConnected($relayUrl)) {
                return;
            }

            $session = $this->requireSession($relayUrl);
            $session->setConnection($session->getConnection()->withoutSubscription($subscriptionId));
            $session->removeHandler($subscriptionId);

            $session->getWebsocket()->sendText(new CloseMessage($subscriptionId)->toJson());
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
        $session = $this->requireSession($relayUrl);
        $websocket = $session->getWebsocket();
        $eventIdHex = $event->getId()->toHex();

        $session->setPendingEvent($eventIdHex, $event);
        $future = $session->trackPublish($eventIdHex);

        try {
            $websocket->sendText(new EventMessage($event)->toJson());
        } catch (Throwable $e) {
            $this->handleConnectionError($relayUrl, $e);
        }

        return $future;
    }

    #[Override]
    public function awaitPendingPublishes(RelayUrl $relayUrl, ?float $timeoutSeconds = null): void
    {
        $session = $this->sessions[(string) $relayUrl] ?? null;
        if (null === $session) {
            return;
        }

        $futures = [];
        foreach ($session->pendingResponses() as $deferred) {
            $futures[] = $deferred->getFuture();
        }
        foreach ($session->authRetryQueue() as $parked) {
            $futures[] = $parked->getDeferred()->getFuture();
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
    public function ping(RelayUrl $relayUrl): void
    {
        $websocket = $this->requireSession($relayUrl)->getWebsocket();

        try {
            $websocket->ping();
        } catch (Throwable $e) {
            throw ConnectionException::forRelay($relayUrl, $e->getMessage(), $e);
        }
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
        return ($this->sessions[(string) $relayUrl] ?? null)?->getConnection();
    }

    #[Override]
    public function getAllConnections(): RelayConnectionCollection
    {
        return new RelayConnectionCollection(array_map(
            static fn (RelaySession $session): RelayConnection => $session->getConnection(),
            array_values($this->sessions),
        ));
    }

    private function requireSession(RelayUrl $relayUrl): RelaySession
    {
        $session = $this->sessions[(string) $relayUrl] ?? null;

        if (null === $session) {
            throw ConnectionException::forRelay($relayUrl, 'Websocket not available');
        }

        return $session;
    }

    private function startMessageHandler(RelayUrl $relayUrl, WebsocketConnection $websocket, int $generation): void
    {
        $urlString = (string) $relayUrl;

        $task = async(weakClosure(function () use ($relayUrl, $websocket, $urlString, $generation) {
            try {
                foreach ($websocket as $message) {
                    $payload = $message->buffer();
                    async(weakClosure(function () use ($relayUrl, $payload): void {
                        $this->handleMessage($relayUrl, $payload);
                    }))->ignore();
                }

                if (($this->connectionGenerations[$urlString] ?? 0) === $generation) {
                    $this->handleConnectionError(
                        $relayUrl,
                        new RuntimeException('WebSocket closed by remote'),
                        $generation
                    );
                }
            } catch (Throwable $e) {
                if (($this->connectionGenerations[$urlString] ?? 0) !== $generation) {
                    return;
                }
                $this->handleConnectionError($relayUrl, $e, $generation);
            }
        }));

        $task->catch(weakClosure(function (Throwable $e) use ($relayUrl, $urlString, $generation) {
            try {
                if (($this->connectionGenerations[$urlString] ?? 0) === $generation) {
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

    private function handleMessage(RelayUrl $relayUrl, string $jsonMessage): void
    {
        $session = $this->sessions[(string) $relayUrl] ?? null;

        if (null === $session) {
            return;
        }

        try {
            $message = $this->deserialiser->deserialiseRelayMessage($jsonMessage);

            if (null === $message) {
                $this->logger->warning('Unknown or malformed relay message', [
                    'relay' => (string) $relayUrl,
                ]);

                return;
            }

            match (true) {
                $message instanceof RelayEventMessage => $this->handleEventMessage($relayUrl, $session, $message),
                $message instanceof OkMessage => $this->handleOkMessage($relayUrl, $session, $message),
                $message instanceof EoseMessage => $this->handleEoseMessage($session, $message),
                $message instanceof ClosedMessage => $this->handleClosedMessage($session, $message),
                $message instanceof NoticeMessage => $this->handleNoticeMessage($relayUrl, $session, $message),
                $message instanceof AuthMessage => $this->handleAuthMessage($relayUrl, $message),
                default => $this->logger->warning('Unhandled relay message type', [
                    'relay' => (string) $relayUrl,
                    'message_type' => $message->getType(),
                ]),
            };
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Unknown or malformed relay message', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to handle relay message', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleEventMessage(RelayUrl $relayUrl, RelaySession $session, RelayEventMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        try {
            $handler = $session->getHandler($subscriptionId);

            if (null !== $handler) {
                $handler->handleEvent($message->getEvent(), $subscriptionId);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to process event message', [
                'relay' => (string) $relayUrl,
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleOkMessage(RelayUrl $relayUrl, RelaySession $session, OkMessage $message): void
    {
        if ($this->isAuthOkResponse($relayUrl, $session, $message)) {
            return;
        }

        $eventIdHex = $message->getEventId()->toHex();
        $future = $session->getPendingResponse($eventIdHex);

        if (null === $future) {
            return;
        }

        $session->removePendingResponse($eventIdHex);

        if ($message->isAuthRequired()) {
            $session->parkPublish(new ParkedPublish($eventIdHex, $future));

            return;
        }

        $session->removePendingEvent($eventIdHex);
        $future->complete($message->isAccepted()
            ? PublishResult::accepted($message->getMessage())
            : PublishResult::rejected($message->getMessage()));
    }

    private function isAuthOkResponse(RelayUrl $relayUrl, RelaySession $session, OkMessage $message): bool
    {
        $authEventIdHex = $message->getEventId()->toHex();

        if (!$session->isPendingAuth($authEventIdHex)) {
            return false;
        }

        $session->clearPendingAuth($authEventIdHex);

        if ($message->isAccepted()) {
            $this->flushAuthRetryQueue($relayUrl, $session);
        } else {
            $this->failAuthRetryQueue($session, $message->getMessage());
        }

        return true;
    }

    private function flushAuthRetryQueue(RelayUrl $relayUrl, RelaySession $session): void
    {
        foreach ($session->takeAuthRetryQueue() as $parked) {
            $eventIdHex = $parked->getEventIdHex();
            $event = $session->getPendingEvent($eventIdHex);

            if (null === $event) {
                $parked->getDeferred()->error(
                    ConnectionException::forRelay($relayUrl, 'Auth retry failed: event no longer available')
                );
                continue;
            }

            $session->setPendingResponse($eventIdHex, $parked->getDeferred());

            try {
                $session->getWebsocket()->sendText(new EventMessage($event)->toJson());
            } catch (Throwable $e) {
                $session->removePendingResponse($eventIdHex);
                $session->removePendingEvent($eventIdHex);
                $parked->getDeferred()->error(
                    ConnectionException::forRelay($relayUrl, 'Auth retry failed: '.$e->getMessage())
                );
            }
        }
    }

    private function failAuthRetryQueue(RelaySession $session, string $reason): void
    {
        foreach ($session->takeAuthRetryQueue() as $parked) {
            $session->removePendingEvent($parked->getEventIdHex());
            $parked->getDeferred()->complete(PublishResult::rejected('auth-required, auth rejected: '.$reason));
        }
    }

    private function handleEoseMessage(RelaySession $session, EoseMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Live));

        $session->getHandler($subscriptionId)?->handleEose($subscriptionId);
    }

    private function handleClosedMessage(RelaySession $session, ClosedMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $reason = $message->getMessage() ?: 'No reason provided';

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $handler = $session->getHandler($subscriptionId);

        $session->setConnection(
            $session->getConnection()
                ->withSubscriptionState($subscriptionId, SubscriptionState::ClosedByRelay)
                ->withoutSubscription($subscriptionId)
        );
        $session->removeHandler($subscriptionId);

        $handler?->handleClosed($subscriptionId, $reason);
    }

    private function handleNoticeMessage(RelayUrl $relayUrl, RelaySession $session, NoticeMessage $message): void
    {
        $notice = $message->getMessage();

        $this->logger->info('NOTICE message received from relay', [
            'relay' => (string) $relayUrl,
            'notice' => $notice,
        ]);

        if (self::APPLICATION_PING_NOTICE === strtolower(trim($notice))) {
            $this->respondToApplicationPing($relayUrl, $session);

            return;
        }

        foreach ($session->distinctHandlers() as $handler) {
            $handler->handleNotice($relayUrl, $notice);
        }
    }

    // Deliberate: keep-alive reply via CLOSE for a throwaway subscription - see ADR-0003
    private function respondToApplicationPing(RelayUrl $relayUrl, RelaySession $session): void
    {
        $keepAliveSubscriptionId = SubscriptionId::fromString(self::KEEP_ALIVE_SUBSCRIPTION_ID);

        if (null === $keepAliveSubscriptionId) {
            return;
        }

        try {
            $session->getWebsocket()->sendText(new CloseMessage($keepAliveSubscriptionId)->toJson());

            $this->logger->debug('Responded to application-level ping', [
                'relay' => (string) $relayUrl,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to respond to application-level ping', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleAuthMessage(RelayUrl $relayUrl, AuthMessage $message): void
    {
        if (null === $this->authHandler) {
            $this->logger->debug('AUTH challenge received but no handler configured', [
                'relay' => (string) $relayUrl,
            ]);

            return;
        }

        try {
            $signedEvent = $this->authHandler->handleAuthChallenge($relayUrl, $message->getChallenge());

            if (null !== $signedEvent) {
                $this->sendAuth($relayUrl, $signedEvent);

                $this->logger->debug('AUTH response sent', [
                    'relay' => (string) $relayUrl,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('AUTH challenge handler failed', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleConnectionError(RelayUrl $relayUrl, Throwable $error, ?int $generation = null): void
    {
        $urlString = (string) $relayUrl;

        if (null !== $generation && ($this->connectionGenerations[$urlString] ?? 0) !== $generation) {
            return;
        }

        $session = $this->sessions[$urlString] ?? null;

        if (null === $session) {
            return;
        }

        if (ConnectionState::FAILED === $session->getConnection()->getState()) {
            return;
        }

        $config = $session->getConnection()->getConfig();
        $activeSubscriptions = $session->getConnection()->getSubscriptions();
        $session->setConnection($session->getConnection()->withState(ConnectionState::FAILED)->withoutSubscriptions());

        foreach ($activeSubscriptions as $subscription) {
            $subscriptionId = $subscription->getId();
            try {
                $handler = $session->getHandler($subscriptionId);
                $session->removeHandler($subscriptionId);

                if (null !== $handler) {
                    $handler->handleClosed($subscriptionId, 'Connection error: '.$error->getMessage());
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to notify handler of connection error', [
                    'relay' => $urlString,
                    'subscription_id' => (string) $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($session->pendingResponses() as $key => $deferred) {
            $session->removePendingResponse($key);
            $deferred->error(ConnectionException::forRelay($relayUrl, $error->getMessage(), $error));
        }

        foreach ($session->takeAuthRetryQueue() as $parked) {
            $parked->getDeferred()->error(ConnectionException::forRelay($relayUrl, $error->getMessage(), $error));
        }

        $session->loseWebsocket();

        if ($config->isAutoReconnect()) {
            $this->scheduleReconnect($relayUrl, $config);
        }
    }

    private function scheduleReconnect(RelayUrl $relayUrl, ConnectionConfig $config): void
    {
        $urlString = (string) $relayUrl;

        if (isset($this->reconnectCancellations[$urlString])) {
            return;
        }

        $deferred = new DeferredCancellation();
        $this->reconnectCancellations[$urlString] = $deferred;
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

                try {
                    $this->connect($relayUrl, $config);
                } catch (Throwable $e) {
                    $this->logger->warning('Relay reconnect attempt failed', [
                        'relay' => $urlString,
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if (!isset($this->reconnectCancellations[$urlString])) {
                    $this->disconnect($relayUrl);

                    return;
                }

                unset($this->reconnectCancellations[$urlString]);

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

            unset($this->reconnectCancellations[$urlString]);

            $this->logger->error('Relay reconnect gave up after max attempts', [
                'relay' => $urlString,
                'attempts' => $attempt,
            ]);
        }))->ignore();
    }
}
