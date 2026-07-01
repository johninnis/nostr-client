<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Override;

final class RecordingReconnectionListener implements ReconnectionListenerInterface
{
    /** @var list<string> */
    public array $reconnectedRelays = [];

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $firstReconnect;

    public function __construct()
    {
        $this->firstReconnect = new DeferredFuture();
    }

    #[Override]
    public function onReconnected(RelayUrl $relayUrl): void
    {
        $this->reconnectedRelays[] = (string) $relayUrl;

        if (!$this->firstReconnect->isComplete()) {
            $this->firstReconnect->complete();
        }
    }

    public function awaitFirstReconnect(float $timeoutSeconds): void
    {
        $this->firstReconnect->getFuture()->await(new TimeoutCancellation($timeoutSeconds));
    }
}
