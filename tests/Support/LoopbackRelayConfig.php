<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Relay\Application\Port\RelayConfigInterface;
use InvalidArgumentException;
use Override;

/**
 * Binds the relay to loopback on an ephemeral port (port 0), so the smoke test never
 * collides with a fixed port; the real port is read back from getListeningAddress().
 */
final class LoopbackRelayConfig implements RelayConfigInterface
{
    #[Override]
    public function getHost(): string
    {
        return '127.0.0.1';
    }

    #[Override]
    public function getPort(): int
    {
        return 0;
    }

    #[Override]
    public function getMaxConnections(): int
    {
        return 50;
    }

    #[Override]
    public function getRelayUrl(): RelayUrl
    {
        return RelayUrl::tryFromString('ws://127.0.0.1')
            ?? throw new InvalidArgumentException('Invalid loopback relay URL');
    }

    /**
     * @return list<non-empty-string>
     */
    #[Override]
    public function getTrustedProxies(): array
    {
        return [];
    }
}
