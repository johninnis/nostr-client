<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ConnectionErrorHandler
{
    public function __construct(
        private RelaySessionRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function fail(RelayUrl $relayUrl, Throwable $error, ?int $generation = null): ?ConnectionConfig
    {
        if (null !== $generation && $this->registry->generation($relayUrl) !== $generation) {
            return null;
        }

        $session = $this->registry->find($relayUrl);

        if (null === $session) {
            return null;
        }

        if (ConnectionState::FAILED === $session->getConnection()->getState()) {
            return null;
        }

        $config = $session->getConnection()->getConfig();
        $activeSubscriptions = $session->getConnection()->getSubscriptions();
        $session->setConnection($session->getConnection()->withState(ConnectionState::FAILED)->withoutSubscriptions());

        $this->notifySubscribers($session, $activeSubscriptions, $error);
        $this->failPendingPublishes($session, $error);

        $session->loseWebsocket();
        $this->registry->cancelHeartbeat($relayUrl);

        return $config->isAutoReconnect() ? $config : null;
    }

    private function notifySubscribers(RelaySession $session, SubscriptionCollection $subscriptions, Throwable $error): void
    {
        foreach ($subscriptions as $subscription) {
            $subscriptionId = $subscription->getId();
            try {
                $handler = $session->getHandler($subscriptionId);
                $session->removeHandler($subscriptionId);

                $handler?->handleClosed($subscriptionId, 'Connection error: '.$error->getMessage());
            } catch (Throwable $e) {
                $this->logger->warning('Failed to notify handler of connection error', [
                    'relay' => (string) $session->getConnection()->getRelayUrl(),
                    'subscription_id' => (string) $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function failPendingPublishes(RelaySession $session, Throwable $error): void
    {
        $relayUrl = $session->getConnection()->getRelayUrl();

        foreach ($session->pendingResponses() as $key => $deferred) {
            $session->removePendingResponse($key);
            $deferred->error(ConnectionException::forRelay($relayUrl, $error->getMessage(), $error));
        }

        foreach ($session->takeAuthRetryQueue() as $parked) {
            $parked->getDeferred()->error(ConnectionException::forRelay($relayUrl, $error->getMessage(), $error));
        }
    }
}
