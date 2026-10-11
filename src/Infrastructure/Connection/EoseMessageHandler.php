<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\EoseHint;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use InvalidArgumentException;
use Override;

final readonly class EoseMessageHandler implements InboundMessageHandlerInterface
{
    public function __construct(private AuthMessageHandler $auth)
    {
    }

    #[Override]
    public function handledMessageType(): string
    {
        return EoseMessage::class;
    }

    #[Override]
    public function handle(RelayMessage $message, RelaySession $session): void
    {
        match (true) {
            $message instanceof EoseMessage => $this->handleEose($message, $session),
            default => throw new InvalidArgumentException('EoseMessageHandler cannot handle '.$message::class),
        };
    }

    private function handleEose(EoseMessage $message, RelaySession $session): void
    {
        $subscriptionId = $message->getSubscriptionId();
        $state = $session->getConnection()->getSubscriptionState($subscriptionId);

        // Deliberate: a REQ has one end of stored events, so a repeated EOSE is ignored, hint included - see ADR-0021
        if (null === $state || $state->isLive()) {
            return;
        }

        $session->setConnection($session->getConnection()->withSubscriptionState($subscriptionId, SubscriptionState::Live));

        $session->getHandler($subscriptionId)?->handleEose($subscriptionId);

        if ($message->hasHint(EoseHint::Auth)) {
            $this->auth->widenWhenAuthenticated($session, $subscriptionId);
        }
    }
}
