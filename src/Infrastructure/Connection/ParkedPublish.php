<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredFuture;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;

final readonly class ParkedPublish
{
    /**
     * @param DeferredFuture<PublishResult> $deferred
     */
    public function __construct(
        private string $eventIdHex,
        private DeferredFuture $deferred,
    ) {
    }

    public function getEventIdHex(): string
    {
        return $this->eventIdHex;
    }

    /**
     * @return DeferredFuture<PublishResult>
     */
    public function getDeferred(): DeferredFuture
    {
        return $this->deferred;
    }
}
