<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\OkOutcome;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use InvalidArgumentException;
use Override;

final readonly class OkMessageHandler implements InboundMessageHandlerInterface
{
    public function __construct(private AuthMessageHandler $auth)
    {
    }

    #[Override]
    public function handledMessageType(): string
    {
        return OkMessage::class;
    }

    #[Override]
    public function handle(RelayMessage $message, RelaySession $session): void
    {
        match (true) {
            $message instanceof OkMessage => $this->handleOk($message, $session),
            default => throw new InvalidArgumentException('OkMessageHandler cannot handle '.$message::class),
        };
    }

    private function handleOk(OkMessage $message, RelaySession $session): void
    {
        $eventIdHex = $message->getEventId()->toHex();

        if ($session->isAuthAttempt($eventIdHex)) {
            $this->auth->acknowledge($message, $session);

            return;
        }

        if (null === $session->getPendingResponse($eventIdHex)) {
            return;
        }

        $outcome = OkOutcome::classify($message);

        if (OkOutcome::AuthRequired === $outcome && $this->auth->park($session, new ParkedPublish($eventIdHex))) {
            // Deliberate: a parked publish is bounded by the auth timeout, not its own - see ADR-0014
            $session->suspendPublishTimeout($eventIdHex);

            return;
        }

        $session->settlePublish($eventIdHex, OkOutcome::Accepted === $outcome
            ? PublishResult::accepted($message->getMessage())
            : PublishResult::rejected($message->getMessage()));
    }
}
