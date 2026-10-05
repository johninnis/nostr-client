<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

final readonly class SubscriptionRequest
{
    public function __construct(
        private RelayUrl $relay,
        private FilterCollection $filters,
        private ?SubscriptionId $subscriptionId = null,
    ) {
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
