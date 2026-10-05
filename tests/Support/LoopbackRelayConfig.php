<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\EventLimits;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use Override;

final class LoopbackRelayConfig implements RelayConfigInterface
{
    #[Override]
    public function getMaxConnections(): int
    {
        return 50;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return RelayUrl::fromString('ws://127.0.0.1');
    }

    #[Override]
    public function getEventLimits(): EventLimits
    {
        return new EventLimits();
    }
}
