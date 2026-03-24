<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

final readonly class HealthCheckResult
{
    private function __construct(
        private bool $isHealthy,
        private ?float $latencyMs = null,
        private ?string $errorMessage = null,
    ) {
    }

    public static function success(float $latencyMs): self
    {
        return new self(
            isHealthy: true,
            latencyMs: $latencyMs,
        );
    }

    public static function failure(string $errorMessage): self
    {
        return new self(
            isHealthy: false,
            errorMessage: $errorMessage,
        );
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
