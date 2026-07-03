<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;

final readonly class EoseMessageHandler
{
    public function handle(EoseMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Live));

        $session->getHandler($subscriptionId)?->handleEose($subscriptionId);
    }
}
