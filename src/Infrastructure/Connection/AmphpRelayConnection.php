<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Application\Port\ConnectionHandlerInterface;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\Service\MessageSerialiserInterface;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\delay;
use function Amp\weakClosure;

final class AmphpRelayConnection implements ConnectionHandlerInterface
{
    private ?AuthChallengeHandlerInterface $authHandler = null;
    private array $connections = [];
    private array $activeWebSockets = [];
    private array $pendingResponses = [];
    private array $connectionGenerations = [];
    private array $subscriptionHandlers = [];
    private array $authRetryQueue = [];
    private array $pendingEvents = [];
    private array $reconnectCancellations = [];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly MessageSerialiserInterface $serialiser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function setAuthHandler(AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    public function sendAuth(RelayUrl $relayUrl, Event $signedAuthEvent): void
    {
        $websocket = $this->getWebsocket($relayUrl);

        $urlString = (string) $relayUrl;
        $authResponseKey = $urlString.':auth-event:'.$signedAuthEvent->getId()->toHex();
        $this->pendingResponses[$authResponseKey] = new DeferredFuture();

        $message = new ClientAuthMessage($signedAuthEvent);
        $websocket->sendText($message->toJson());
    }

    public function connect(RelayUrl $relayUrl, ConnectionConfig $config): void
    {
        $urlString = (string) $relayUrl;

        if (isset($this->connections[$urlString])) {
            $existingConnection = $this->connections[$urlString];
            if ($existingConnection->isHealthy()) {
                return;
            }
        }

        try {
            $websocket = $this->connectionFactory->createConnection($relayUrl, $config)->await();

            $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, $config);
            $this->connections[$urlString] = $connection;

            $generation = ($this->connectionGenerations[$urlString] ?? 0) + 1;
            $this->connectionGenerations[$urlString] = $generation;

            $this->startMessageHandler($relayUrl, $websocket, $generation);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ConnectionException::forRelay($relayUrl, $e->getMessage(), $e);
        }
    }

    public function disconnect(RelayUrl $relayUrl): void
    {
        $urlString = (string) $relayUrl;

        if (isset($this->reconnectCancellations[$urlString])) {
            $this->reconnectCancellations[$urlString]->cancel();
            unset($this->reconnectCancellations[$urlString]);
        }

        if (isset($this->connections[$urlString])) {
            $connection = $this->connections[$urlString];

            if (ConnectionState::CONNECTED === $connection->getState()) {
                $connection->updateState(ConnectionState::DISCONNECTING);
            }

            $this->connectionGenerations[$urlString] = ($this->connectionGenerations[$urlString] ?? 0) + 1;

            if (isset($this->activeWebSockets[$urlString])) {
                try {
                    $this->activeWebSockets[$urlString]->connection->close();
                } catch (Throwable $e) {
                    $this->logger->debug('Failed to close WebSocket during disconnect', [
                        'relay' => $urlString,
                        'error' => $e->getMessage(),
                    ]);
                }
                unset($this->activeWebSockets[$urlString]);
            }

            $this->clearHandlersForRelay($urlString);
            $this->clearPendingForRelay($urlString);

            if (ConnectionState::DISCONNECTING === $connection->getState()) {
                $connection->updateState(ConnectionState::DISCONNECTED);
            }
            unset($this->connections[$urlString]);
        }
    }

    public function subscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId, Filter $filter, ?EventHandlerInterface $handler = null): void
    {
        $this->subscribeMultiple($relayUrl, $subscriptionId, [$filter], $handler);
    }

    public function subscribeMultiple(RelayUrl $relayUrl, SubscriptionId $subscriptionId, array $filters, ?EventHandlerInterface $handler = null): void
    {
        $websocket = $this->getWebsocket($relayUrl);

        $connection = $this->connections[(string) $relayUrl];
        $connection->addSubscription($subscriptionId, $filters);

        if (null !== $handler) {
            $this->subscriptionHandlers[$this->handlerKey($relayUrl, $subscriptionId)] = $handler;
        }

        $message = new ReqMessage($subscriptionId, $filters);

        try {
            $websocket->sendText($message->toJson());
            if (!$connection->updateSubscriptionState($subscriptionId, SubscriptionState::ACTIVE)) {
                $this->logger->debug('Attempted to update state of unknown subscription', [
                    'relay' => (string) $relayUrl,
                    'subscription_id' => (string) $subscriptionId,
                    'target_state' => SubscriptionState::ACTIVE->value,
                ]);
            }
        } catch (Throwable $e) {
            $this->handleConnectionError($relayUrl, $e);
        }
    }

    public function unsubscribe(RelayUrl $relayUrl, SubscriptionId $subscriptionId): void
    {
        try {
            if (!$this->isConnected($relayUrl)) {
                return;
            }

            $websocket = $this->getWebsocket($relayUrl);
            $connection = $this->connections[(string) $relayUrl];
            $connection->removeSubscription($subscriptionId);
            unset($this->subscriptionHandlers[$this->handlerKey($relayUrl, $subscriptionId)]);

            $message = new CloseMessage($subscriptionId);
            $websocket->sendText($message->toJson());
        } catch (Throwable $e) {
            $this->logger->warning('Failed to unsubscribe', [
                'relay' => (string) $relayUrl,
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function publishEvent(RelayUrl $relayUrl, Event $event): bool
    {
        $websocket = $this->getWebsocket($relayUrl);
        $eventKey = (string) $relayUrl.':'.$event->getId()->toHex();
        $this->pendingEvents[$eventKey] = $event;

        $message = new EventMessage($event);
        $websocket->sendText($message->toJson());

        return true;
    }

    public function ping(RelayUrl $relayUrl): bool
    {
        $websocket = $this->getWebsocket($relayUrl);

        $websocket->ping();

        return true;
    }

    public function isConnected(RelayUrl $relayUrl): bool
    {
        $connection = $this->getConnection($relayUrl);

        return null !== $connection && $connection->isHealthy();
    }

    public function getConnection(RelayUrl $relayUrl): ?RelayConnection
    {
        return $this->connections[(string) $relayUrl] ?? null;
    }

    public function getAllConnections(): RelayConnectionCollection
    {
        return new RelayConnectionCollection(array_values($this->connections));
    }

    private function startMessageHandler(RelayUrl $relayUrl, WebsocketConnection $websocket, int $generation): void
    {
        $urlString = (string) $relayUrl;

        $task = async(weakClosure(function () use ($relayUrl, $websocket, $urlString, $generation) {
            try {
                foreach ($websocket as $message) {
                    $this->handleMessage($relayUrl, $message->buffer());
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

        $this->activeWebSockets[$urlString] = new ActiveWebSocket($websocket, $task);
    }

    private function handleMessage(RelayUrl $relayUrl, string $jsonMessage): void
    {
        $connection = $this->connections[(string) $relayUrl] ?? null;

        if (!$connection) {
            return;
        }

        try {
            $message = $this->serialiser->deserialiseRelayMessage($jsonMessage);

            match (true) {
                $message instanceof RelayEventMessage => $this->handleEventMessage($relayUrl, $message),
                $message instanceof OkMessage => $this->handleOkMessage($relayUrl, $message),
                $message instanceof EoseMessage => $this->handleEoseMessage($relayUrl, $message),
                $message instanceof ClosedMessage => $this->handleClosedMessage($relayUrl, $message),
                $message instanceof NoticeMessage => $this->handleNoticeMessage($relayUrl, $message),
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

    private function handleEventMessage(RelayUrl $relayUrl, RelayEventMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $connection = $this->connections[(string) $relayUrl];

        if ($connection->hasSubscription($subscriptionId)) {
            try {
                $event = $message->getEvent();
                $handler = $this->subscriptionHandlers[$this->handlerKey($relayUrl, $subscriptionId)] ?? null;

                if (null !== $handler) {
                    $handler->handleEvent($event, $subscriptionId);
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to process event message', [
                    'relay' => (string) $relayUrl,
                    'subscription_id' => (string) $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function handleOkMessage(RelayUrl $relayUrl, OkMessage $message): void
    {
        $eventIdHex = $message->getEventId()->toHex();
        $urlString = (string) $relayUrl;
        $responseKey = $urlString.':'.$eventIdHex;

        if ($this->isAuthOkResponse($relayUrl, $message)) {
            return;
        }

        if (isset($this->pendingResponses[$responseKey])) {
            $future = $this->pendingResponses[$responseKey];
            unset($this->pendingResponses[$responseKey]);

            if ($message->isAccepted()) {
                $future->complete(true);
            } elseif ($message->isAuthRequired()) {
                $this->queueAuthRetry($relayUrl, $eventIdHex, $future);
            } else {
                $future->error(
                    ConnectionException::forRelay($relayUrl, 'Event rejected: '.$message->getMessage())
                );
            }
        }
    }

    private function isAuthOkResponse(RelayUrl $relayUrl, OkMessage $message): bool
    {
        $urlString = (string) $relayUrl;
        $retryKey = $urlString.':auth-event:'.$message->getEventId()->toHex();

        if (!isset($this->pendingResponses[$retryKey])) {
            return false;
        }

        $authDeferred = $this->pendingResponses[$retryKey];
        unset($this->pendingResponses[$retryKey]);

        if ($message->isAccepted()) {
            $authDeferred->complete(true);
            $this->flushAuthRetryQueue($relayUrl);
        } else {
            $authDeferred->error(
                ConnectionException::forRelay($relayUrl, 'Auth rejected: '.$message->getMessage())
            );
            $this->failAuthRetryQueue($relayUrl, $message->getMessage());
        }

        return true;
    }

    private function queueAuthRetry(RelayUrl $relayUrl, string $eventIdHex, DeferredFuture $deferred): void
    {
        $urlString = (string) $relayUrl;

        if (!isset($this->authRetryQueue[$urlString])) {
            $this->authRetryQueue[$urlString] = [];
        }

        $this->authRetryQueue[$urlString][] = [
            'event_id_hex' => $eventIdHex,
            'deferred' => $deferred,
        ];
    }

    private function flushAuthRetryQueue(RelayUrl $relayUrl): void
    {
        $urlString = (string) $relayUrl;
        $queue = $this->authRetryQueue[$urlString] ?? [];
        unset($this->authRetryQueue[$urlString]);

        foreach ($queue as $entry) {
            $eventKey = $urlString.':'.$entry['event_id_hex'];
            $event = $this->pendingEvents[$eventKey] ?? null;

            if (null === $event) {
                $entry['deferred']->error(
                    ConnectionException::forRelay($relayUrl, 'Auth retry failed: event no longer available')
                );
                continue;
            }

            $responseKey = $urlString.':'.$entry['event_id_hex'];
            $this->pendingResponses[$responseKey] = $entry['deferred'];

            try {
                $websocket = $this->getWebsocket($relayUrl);
                $websocket->sendText((new EventMessage($event))->toJson());
            } catch (Throwable $e) {
                unset($this->pendingResponses[$responseKey]);
                $entry['deferred']->error(
                    ConnectionException::forRelay($relayUrl, 'Auth retry failed: '.$e->getMessage())
                );
            }
        }
    }

    private function failAuthRetryQueue(RelayUrl $relayUrl, string $reason): void
    {
        $urlString = (string) $relayUrl;
        $queue = $this->authRetryQueue[$urlString] ?? [];
        unset($this->authRetryQueue[$urlString]);

        foreach ($queue as $entry) {
            $entry['deferred']->error(
                ConnectionException::forRelay($relayUrl, 'Auth failed: '.$reason)
            );
        }
    }

    private function handleEoseMessage(RelayUrl $relayUrl, EoseMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $connection = $this->connections[(string) $relayUrl] ?? null;

        if (!$connection || !$connection->hasSubscription($subscriptionId)) {
            return;
        }

        if (!$connection->updateSubscriptionState($subscriptionId, SubscriptionState::LIVE)) {
            $this->logger->debug('Attempted to update state of unknown subscription', [
                'relay' => (string) $relayUrl,
                'subscription_id' => (string) $subscriptionId,
                'target_state' => SubscriptionState::LIVE->value,
            ]);
        }

        $handler = $this->subscriptionHandlers[$this->handlerKey($relayUrl, $subscriptionId)] ?? null;

        if (null !== $handler) {
            $handler->handleEose($subscriptionId);
        }
    }

    private function handleClosedMessage(RelayUrl $relayUrl, ClosedMessage $message): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $reason = $message->getMessage() ?: 'No reason provided';
        $connection = $this->connections[(string) $relayUrl] ?? null;

        if (!$connection || !$connection->hasSubscription($subscriptionId)) {
            return;
        }

        if (!$connection->updateSubscriptionState($subscriptionId, SubscriptionState::CLOSED_BY_RELAY)) {
            $this->logger->debug('Attempted to update state of unknown subscription', [
                'relay' => (string) $relayUrl,
                'subscription_id' => (string) $subscriptionId,
                'target_state' => SubscriptionState::CLOSED_BY_RELAY->value,
            ]);
        }

        $key = $this->handlerKey($relayUrl, $subscriptionId);
        $handler = $this->subscriptionHandlers[$key] ?? null;

        try {
            if (null !== $handler) {
                $handler->handleClosed($subscriptionId, $reason);
            }
        } finally {
            $connection->removeSubscription($subscriptionId);
            unset($this->subscriptionHandlers[$key]);
        }
    }

    private function handleNoticeMessage(RelayUrl $relayUrl, NoticeMessage $message): void
    {
        $notice = $message->getMessage();

        $this->logger->info('NOTICE message received from relay', [
            'relay' => (string) $relayUrl,
            'notice' => $notice,
        ]);

        // Some relays (like relay.ditto.pub) use application-level ping via NOTICE
        // They expect ANY message back within a timeout to prove the client is alive
        if ('ping' === strtolower(trim($notice))) {
            try {
                $websocket = $this->getWebsocket($relayUrl);
                // Send a CLOSE for a non-existent subscription as a keep-alive response
                // This is cheaper than a NOTICE and valid per Nostr protocol
                $keepAliveMessage = new CloseMessage(SubscriptionId::fromString('keepalive'));
                $websocket->sendText($keepAliveMessage->toJson());

                $this->logger->debug('Responded to application-level ping', [
                    'relay' => (string) $relayUrl,
                ]);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to respond to application-level ping', [
                    'relay' => (string) $relayUrl,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $prefix = (string) $relayUrl.':';
        foreach ($this->subscriptionHandlers as $key => $handler) {
            if (str_starts_with($key, $prefix)) {
                $handler->handleNotice($relayUrl, $notice);
            }
        }
    }

    private function handleAuthMessage(RelayUrl $relayUrl, AuthMessage $message): void
    {
        $challenge = $message->getChallenge();

        if (null === $this->authHandler) {
            $this->logger->debug('AUTH challenge received but no handler configured', [
                'relay' => (string) $relayUrl,
            ]);

            return;
        }

        try {
            $signedEvent = $this->authHandler->handleAuthChallenge($relayUrl, $challenge);

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

        $connection = $this->connections[$urlString] ?? null;

        if ($connection) {
            $connection->updateState(ConnectionState::FAILED);

            $activeSubscriptions = $connection->getSubscriptions();
            $connection->clearSubscriptions();

            foreach ($activeSubscriptions as $subscriptionIdString => $_) {
                try {
                    $subscriptionId = SubscriptionId::fromString($subscriptionIdString);
                    $key = $this->handlerKey($relayUrl, $subscriptionId);
                    $handler = $this->subscriptionHandlers[$key] ?? null;
                    unset($this->subscriptionHandlers[$key]);

                    if (null !== $handler) {
                        $handler->handleClosed($subscriptionId, 'Connection error: '.$error->getMessage());
                    }
                } catch (Throwable $e) {
                    $this->logger->warning('Failed to notify handler of connection error', [
                        'relay' => $urlString,
                        'subscription_id' => $subscriptionIdString,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $prefix = $urlString.':';
        foreach ($this->pendingResponses as $key => $deferred) {
            if (str_starts_with($key, $prefix)) {
                unset($this->pendingResponses[$key]);
                $deferred->error(ConnectionException::forRelay($relayUrl, $error->getMessage(), $error));
            }
        }

        unset($this->activeWebSockets[$urlString]);

        if (null !== $connection && $connection->getConfig()->isAutoReconnect()) {
            $this->scheduleReconnect($relayUrl, $connection->getConfig());
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
            $initialMs = $config->getReconnectInitialDelayMs();
            $maxMs = $config->getReconnectMaxDelayMs();
            $maxAttempts = $config->getReconnectMaxAttempts();
            $attempt = 0;

            while (0 === $maxAttempts || $attempt < $maxAttempts) {
                $delayMs = (int) min($initialMs * (2 ** $attempt), $maxMs);
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

                $callback = $config->getOnReconnected();
                if (null !== $callback) {
                    try {
                        $callback($relayUrl);
                    } catch (Throwable $e) {
                        $this->logger->error('onReconnected callback threw', [
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

    private function handlerKey(RelayUrl $relayUrl, SubscriptionId $subscriptionId): string
    {
        return (string) $relayUrl.':'.(string) $subscriptionId;
    }

    private function clearHandlersForRelay(string $urlString): void
    {
        $prefix = $urlString.':';
        foreach (array_keys($this->subscriptionHandlers) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->subscriptionHandlers[$key]);
            }
        }
    }

    private function clearPendingForRelay(string $urlString): void
    {
        unset($this->authRetryQueue[$urlString]);

        $prefix = $urlString.':';
        foreach (array_keys($this->pendingEvents) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->pendingEvents[$key]);
            }
        }
    }

    private function getWebsocket(RelayUrl $relayUrl): WebsocketConnection
    {
        $urlString = (string) $relayUrl;

        if (!isset($this->activeWebSockets[$urlString])) {
            throw ConnectionException::forRelay($relayUrl, 'Websocket not available');
        }

        return $this->activeWebSockets[$urlString]->connection;
    }
}
