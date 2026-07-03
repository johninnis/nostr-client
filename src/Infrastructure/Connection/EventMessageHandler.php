<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class EventMessageHandler implements RelayMessageHandlerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[Override]
    public function handles(): RelayMessageType
    {
        return RelayMessageType::Event;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly.
        if (!$message instanceof EventMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

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
