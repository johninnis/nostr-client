<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;

final class RecordingEventHandler implements EventHandlerInterface
{
    /** @var list<string> */
    public array $eventIds = [];

    /** @var list<string> */
    public array $closedReasons = [];

    #[Override]
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        $this->eventIds[] = $event->getId()->toHex();
    }

    #[Override]
    public function handleEose(SubscriptionId $subscriptionId): void
    {
    }

    #[Override]
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        $this->closedReasons[] = $message;
    }

    #[Override]
    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
    }
}
