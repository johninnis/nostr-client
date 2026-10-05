<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
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

        $this->logger->warning('Relay connection dropped', [
            'relay' => (string) $relayUrl,
            'error' => $error->getMessage(),
        ]);

        $this->notifySubscribers($session, $activeSubscriptions);
        $session->settleAllPublishes(PublishResult::unavailable(RelayUnavailability::Disconnected));
        $session->takeParked();

        $session->loseWebsocket();
        $this->registry->cancelHeartbeat($relayUrl);

        return $config->isAutoReconnect() ? $config : null;
    }

    private function notifySubscribers(RelaySession $session, SubscriptionCollection $subscriptions): void
    {
        foreach ($subscriptions as $subscription) {
            $subscriptionId = $subscription->getId();
            try {
                $handler = $session->getHandler($subscriptionId);
                $session->removeHandler($subscriptionId);

                $handler?->handleClosed($subscriptionId, RelayUnavailability::Disconnected->value);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to notify handler of connection error', [
                    'relay' => (string) $session->getConnection()->getRelayUrl(),
                    'subscription_id' => (string) $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
