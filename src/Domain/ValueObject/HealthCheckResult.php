<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final readonly class HealthCheckResult
{
    private function __construct(
        private RelayUrl $relayUrl,
        private bool $isHealthy,
        private ?float $latencyMs = null,
        private ?string $errorMessage = null,
    ) {
    }

    public static function success(RelayUrl $relayUrl, float $latencyMs): self
    {
        return new self(
            relayUrl: $relayUrl,
            isHealthy: true,
            latencyMs: $latencyMs,
        );
    }

    public static function failure(RelayUrl $relayUrl, string $errorMessage): self
    {
        return new self(
            relayUrl: $relayUrl,
            isHealthy: false,
            errorMessage: $errorMessage,
        );
    }

    public function getRelayUrl(): RelayUrl
    {
        return $this->relayUrl;
    }

    public function isHealthy(): bool
    {
        return $this->isHealthy;
    }

    public function getLatencyMs(): ?float
    {
        return $this->latencyMs;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
