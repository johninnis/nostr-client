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
            self::CONNECTED => in_array($target, [self::DISCONNECTING, self::FAILED], true),
            self::DISCONNECTING => in_array($target, [self::DISCONNECTED, self::FAILED], true),
            self::FAILED => self::CONNECTED === $target,
        };
    }
}
