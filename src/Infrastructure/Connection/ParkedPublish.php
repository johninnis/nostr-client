<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredFuture;

final readonly class ParkedPublish
{
    /**
     * @param DeferredFuture<bool> $deferred
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
     * @return DeferredFuture<bool>
     */
    public function getDeferred(): DeferredFuture
    {
        return $this->deferred;
    }
}
