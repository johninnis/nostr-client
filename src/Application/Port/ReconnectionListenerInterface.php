<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

interface ReconnectionListenerInterface
{
    public function onReconnected(RelayUrl $relayUrl): void;
}
