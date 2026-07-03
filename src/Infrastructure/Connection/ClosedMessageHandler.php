<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use LogicException;
use Override;

final readonly class ClosedMessageHandler implements RelayMessageHandlerInterface
{
    #[Override]
    public function handles(): RelayMessageType
    {
        return RelayMessageType::Closed;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly.
        if (!$message instanceof ClosedMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        $subscriptionId = $message->getSubscriptionId();
        $reason = $message->getMessage() ?: 'No reason provided';

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $handler = $session->getHandler($subscriptionId);

        $session->setConnection(
            $session->getConnection()
                ->withSubscriptionState($subscriptionId, SubscriptionState::ClosedByRelay)
                ->withoutSubscription($subscriptionId)
        );
        $session->removeHandler($subscriptionId);

        $handler?->handleClosed($subscriptionId, $reason);
    }
}
