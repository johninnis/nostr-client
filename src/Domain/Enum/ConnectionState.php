<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Enum;

enum ConnectionState: string
{
    case DISCONNECTED = 'disconnected';
    case CONNECTED = 'connected';
    case DISCONNECTING = 'disconnecting';
    case FAILED = 'failed';

    public function isConnected(): bool
    {
        return self::CONNECTED === $this;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DISCONNECTED => self::CONNECTED === $target,
            self::CONNECTED => self::DISCONNECTING === $target || self::FAILED === $target,
            self::DISCONNECTING => self::DISCONNECTED === $target || self::FAILED === $target,
            self::FAILED => self::CONNECTED === $target,
        };
    }
}
