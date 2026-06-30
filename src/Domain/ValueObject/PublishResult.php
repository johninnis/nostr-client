<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

/**
 * A relay's verdict on a published event, mirroring the NIP-01 `OK` frame: accepted,
 * or rejected with the relay's reason. Both are anticipated outcomes returned to the
 * caller — only a broken connection is a thrown fault.
 */
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
