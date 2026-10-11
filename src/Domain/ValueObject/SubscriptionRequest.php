<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Client\Domain\Enum\ReservedSubscriptionId;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;

final readonly class SubscriptionRequest
{
    public function __construct(
        private RelayUrl $relay,
        private FilterCollection $filters,
        private ?SubscriptionId $subscriptionId = null,
    ) {
        // Deliberate: a reserved id is the client's own, so no heartbeat or ping reply can close a caller's subscription - see ADR-0020
        if (null !== $subscriptionId && null !== ReservedSubscriptionId::tryFrom((string) $subscriptionId)) {
            throw new InvalidArgumentException(sprintf('Subscription id "%s" is reserved by the client', $subscriptionId));
        }
    }

    public static function for(RelayUrl $relay, Filter $filter, ?SubscriptionId $subscriptionId = null): self
    {
        return new self($relay, new FilterCollection([$filter]), $subscriptionId);
    }

    public function getRelay(): RelayUrl
    {
        return $this->relay;
    }

    public function getFilters(): FilterCollection
    {
        return $this->filters;
    }

    public function getSubscriptionId(): ?SubscriptionId
    {
        return $this->subscriptionId;
    }

    public function withFilters(FilterCollection $filters): self
    {
        return new self($this->relay, $filters, $this->subscriptionId);
    }

    public function withSubscriptionId(SubscriptionId $subscriptionId): self
    {
        return new self($this->relay, $this->filters, $subscriptionId);
    }
}
