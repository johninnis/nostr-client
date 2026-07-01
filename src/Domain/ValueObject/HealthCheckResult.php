<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final readonly class HealthCheckResult
{
    private function __construct(
        private RelayUrl $relayUrl,
        private bool $isHealthy,
        private ?string $errorMessage = null,
    ) {
    }

    public static function success(RelayUrl $relayUrl): self
    {
        return new self($relayUrl, true);
    }

    public static function failure(RelayUrl $relayUrl, string $errorMessage): self
    {
        return new self($relayUrl, false, $errorMessage);
    }

    public function getRelayUrl(): RelayUrl
    {
        return $this->relayUrl;
    }

    public function isHealthy(): bool
    {
        return $this->isHealthy;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
