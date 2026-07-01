<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;

/**
 * Captures the first event delivered to a subscription and lets the test await it
 * under a timeout, so a round-trip can be asserted without polling.
 */
final class CapturingEventHandler implements EventHandlerInterface
{
    /** @var DeferredFuture<Event> */
    private readonly DeferredFuture $firstEvent;

    public function __construct()
    {
        $this->firstEvent = new DeferredFuture();
    }

    #[Override]
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        if (!$this->firstEvent->isComplete()) {
            $this->firstEvent->complete($event);
        }
    }

    #[Override]
    public function handleEose(SubscriptionId $subscriptionId): void
    {
    }

    #[Override]
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
    }

    #[Override]
    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
    }

    public function awaitFirstEvent(float $timeoutSeconds): Event
    {
        return $this->firstEvent->getFuture()->await(new TimeoutCancellation($timeoutSeconds));
    }
}
