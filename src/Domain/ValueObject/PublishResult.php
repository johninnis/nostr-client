<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

final readonly class PublishResult
{
    private function __construct(
        private bool $accepted,
        private string $message,
    ) {
    }

    public static function accepted(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function rejected(string $message): self
    {
        return new self(false, $message);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
