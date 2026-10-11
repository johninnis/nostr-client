<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

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
        $session->reissueSubscription($this->subscriptionId);
    }

    #[Override]
    public function refuse(RelaySession $session, string $reason): void
    {
        $session->closeSubscription($this->subscriptionId, $reason);
    }
}
