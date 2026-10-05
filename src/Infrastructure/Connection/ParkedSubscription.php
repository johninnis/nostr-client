<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\ByteStream\StreamException;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;

final readonly class ParkedSubscription implements ParkedWorkInterface
{
    public function __construct(private SubscriptionId $subscriptionId)
    {
    }

    #[Override]
    public function resume(RelaySession $session): void
    {
        $subscription = $session->getConnection()->getSubscriptions()->get($this->subscriptionId);

        if (null === $subscription) {
            return;
        }

        try {
            $session->send(ReqMessage::from($this->subscriptionId, $subscription->getFilters()));
            $session->setConnection($session->getConnection()->withSubscriptionState($this->subscriptionId, SubscriptionState::Active));
        } catch (ConnectionException|StreamException) {
            $session->closeSubscription($this->subscriptionId, RelayUnavailability::Disconnected->value);
        }
    }

    #[Override]
    public function refuse(RelaySession $session, string $reason): void
    {
        $session->closeSubscription($this->subscriptionId, $reason);
    }
}
