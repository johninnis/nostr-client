<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use InvalidArgumentException;
use Override;

final readonly class EventMessageHandler implements InboundMessageHandlerInterface
{
    #[Override]
    public function handledMessageType(): string
    {
        return EventMessage::class;
    }

    #[Override]
    public function handle(RelayMessage $message, RelaySession $session): void
    {
        match (true) {
            $message instanceof EventMessage => $this->handleEvent($message, $session),
            default => throw new InvalidArgumentException('EventMessageHandler cannot handle '.$message::class),
        };
    }

    private function handleEvent(EventMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $session->getHandler($subscriptionId)?->handleEvent($message->getEvent(), $subscriptionId);
    }
}
