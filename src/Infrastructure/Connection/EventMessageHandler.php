<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;

final readonly class EventMessageHandler
{
    public function handle(EventMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $session->getHandler($subscriptionId)?->handleEvent($message->getEvent(), $subscriptionId);
    }
}
