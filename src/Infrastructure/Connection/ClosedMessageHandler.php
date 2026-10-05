<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use InvalidArgumentException;
use Override;

final readonly class ClosedMessageHandler implements InboundMessageHandlerInterface
{
    public function __construct(private AuthMessageHandler $auth)
    {
    }

    #[Override]
    public function handledMessageType(): string
    {
        return ClosedMessage::class;
    }

    #[Override]
    public function handle(RelayMessage $message, RelaySession $session): void
    {
        match (true) {
            $message instanceof ClosedMessage => $this->handleClosed($message, $session),
            default => throw new InvalidArgumentException('ClosedMessageHandler cannot handle '.$message::class),
        };
    }

    private function handleClosed(ClosedMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();

        if (!$session->getConnection()->hasSubscription($subscriptionId)) {
            return;
        }

        if (ReasonPrefix::AuthRequired === $message->getReasonPrefix()) {
            $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Pending));

            if ($this->auth->park($session, new ParkedSubscription($subscriptionId))) {
                return;
            }
        }

        $session->closeSubscription($subscriptionId, $message->getMessage() ?: 'No reason provided');
    }
}
