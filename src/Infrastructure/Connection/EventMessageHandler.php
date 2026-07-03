<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class EventMessageHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(EventMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        try {
            $session->getHandler($subscriptionId)?->handleEvent($message->getEvent(), $subscriptionId);
        } catch (Throwable $e) {
            $this->logger->error('Failed to process event message', [
                'relay' => (string) $session->getConnection()->getRelayUrl(),
                'subscription_id' => (string) $subscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
