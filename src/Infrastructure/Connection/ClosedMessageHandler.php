<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;

final readonly class ClosedMessageHandler
{
    public function handle(ClosedMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $reason = $message->getMessage() ?: 'No reason provided';

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $handler = $session->getHandler($subscriptionId);

        $session->setConnection($session->getConnection()->withoutSubscription($subscriptionId));
        $session->removeHandler($subscriptionId);

        $handler?->handleClosed($subscriptionId, $reason);
    }
}
