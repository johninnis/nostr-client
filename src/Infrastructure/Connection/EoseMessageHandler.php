<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use LogicException;
use Override;

final readonly class EoseMessageHandler implements RelayMessageHandlerInterface
{
    #[Override]
    public function handles(): RelayMessageType
    {
        return RelayMessageType::Eose;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly.
        if (!$message instanceof EoseMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Live));

        $session->getHandler($subscriptionId)?->handleEose($subscriptionId);
    }
}
