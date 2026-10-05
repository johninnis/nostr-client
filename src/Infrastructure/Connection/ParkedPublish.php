<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\ByteStream\StreamException;
use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Override;

final readonly class ParkedPublish implements ParkedWorkInterface
{
    public function __construct(private string $eventIdHex)
    {
    }

    #[Override]
    public function resume(RelaySession $session): void
    {
        $deferred = $session->getPendingResponse($this->eventIdHex);
        $event = $session->getPendingEvent($this->eventIdHex);

        if (null === $deferred || null === $event) {
            return;
        }

        $session->startPublishTimeout($this->eventIdHex);

        try {
            $session->send(new EventMessage($event));
        } catch (ConnectionException|StreamException) {
            $session->settlePublish($this->eventIdHex, PublishResult::unavailable(RelayUnavailability::Disconnected));
        }
    }

    #[Override]
    public function refuse(RelaySession $session, string $reason): void
    {
        $session->settlePublish($this->eventIdHex, PublishResult::rejected($reason));
    }
}
