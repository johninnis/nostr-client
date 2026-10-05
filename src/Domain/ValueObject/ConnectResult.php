<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;

final readonly class ConnectResult
{
    private function __construct(
        private bool $connected,
        private string $message,
    ) {
    }

    public static function connected(): self
    {
        return new self(true, '');
    }

    public static function failedToConnect(): self
    {
        return new self(false, RelayUnavailability::FailedToConnect->value);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
