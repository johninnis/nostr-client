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

final readonly class RelayConnection
{
    public function __construct(
        private RelayUrl $relayUrl,
        private ConnectionState $state,
        private ConnectionConfig $config,
        private SubscriptionCollection $subscriptions = new SubscriptionCollection(),
    ) {
    }

    public function getRelayUrl(): RelayUrl
    {
        return $this->relayUrl;
    }

    public function getState(): ConnectionState
    {
        return $this->state;
    }

    public function withState(ConnectionState $state): self
    {
        if (!$this->state->canTransitionTo($state)) {
            throw new InvalidArgumentException("Invalid state transition from {$this->state->value} to {$state->value}");
        }

        return new self($this->relayUrl, $state, $this->config, $this->subscriptions);
    }

    public function getConfig(): ConnectionConfig
    {
        return $this->config;
    }

    public function withSubscription(
        SubscriptionId $subscriptionId,
        FilterCollection $filters,
        SubscriptionState $initialState = SubscriptionState::Pending,
    ): self {
        $subscription = Subscription::create($subscriptionId, $filters);

        if (SubscriptionState::Pending !== $initialState) {
            $subscription = $subscription->withState($initialState);
        }

        return new self($this->relayUrl, $this->state, $this->config, $this->subscriptions->add($subscription));
    }

    public function withoutSubscription(SubscriptionId $subscriptionId): self
    {
        return new self($this->relayUrl, $this->state, $this->config, $this->subscriptions->remove($subscriptionId));
    }

    public function withSubscriptionState(SubscriptionId $subscriptionId, SubscriptionState $state): self
    {
        return new self($this->relayUrl, $this->state, $this->config, $this->subscriptions->withUpdatedState($subscriptionId, $state));
    }

    public function withoutSubscriptions(): self
    {
        return new self($this->relayUrl, $this->state, $this->config, new SubscriptionCollection());
    }

    public function hasSubscription(SubscriptionId $subscriptionId): bool
    {
        return $this->subscriptions->has($subscriptionId);
    }

    public function getSubscriptions(): SubscriptionCollection
    {
        return $this->subscriptions;
    }

    public function getSubscriptionState(SubscriptionId $subscriptionId): ?SubscriptionState
    {
        return $this->subscriptions->getState($subscriptionId);
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
