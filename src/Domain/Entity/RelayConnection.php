<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Entity;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Collection\SubscriptionCollection;
use Innis\Nostr\Core\Domain\Entity\Subscription;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;

final class RelayConnection
{
    private SubscriptionCollection $subscriptions;

    public function __construct(
        private readonly RelayUrl $relayUrl,
        private ConnectionState $state,
        private readonly ConnectionConfig $config,
    ) {
        $this->subscriptions = SubscriptionCollection::empty();
    }

    public function getRelayUrl(): RelayUrl
    {
        return $this->relayUrl;
    }

    public function getState(): ConnectionState
    {
        return $this->state;
    }

    public function updateState(ConnectionState $state): void
    {
        if (!$this->state->canTransitionTo($state)) {
            throw new InvalidArgumentException("Invalid state transition from {$this->state->value} to {$state->value}");
        }

        $this->state = $state;
    }

    public function getConfig(): ConnectionConfig
    {
        return $this->config;
    }

    public function addSubscription(
        SubscriptionId $subscriptionId,
        FilterCollection $filters,
        SubscriptionState $initialState = SubscriptionState::Pending,
    ): void {
        $subscription = Subscription::create($subscriptionId, $filters);

        if (SubscriptionState::Pending !== $initialState) {
            $subscription = $subscription->withState($initialState);
        }

        $this->subscriptions = $this->subscriptions->add($subscription);
    }

    public function removeSubscription(SubscriptionId $subscriptionId): void
    {
        $this->subscriptions = $this->subscriptions->remove($subscriptionId);
    }

    public function hasSubscription(SubscriptionId $subscriptionId): bool
    {
        return $this->subscriptions->has($subscriptionId);
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptions;
    }

    public function updateSubscriptionState(SubscriptionId $subscriptionId, SubscriptionState $state): bool
    {
        if (!$this->subscriptions->has($subscriptionId)) {
            return false;
        }

        $this->subscriptions = $this->subscriptions->withUpdatedState($subscriptionId, $state);

        return true;
    }

    public function getSubscriptionState(SubscriptionId $subscriptionId): ?SubscriptionState
    {
        return $this->subscriptions->getState($subscriptionId);
    }

    public function clearSubscriptions(): void
    {
        $this->subscriptions = SubscriptionCollection::empty();
    }

    public function getSubscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    public function isHealthy(): bool
    {
        return $this->state->isConnected();
    }
}
