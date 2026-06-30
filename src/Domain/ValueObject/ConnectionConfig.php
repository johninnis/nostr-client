<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ConnectionConfig
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $connectionTimeoutSeconds = 10,
        private array $headers = [],
        private ?string $userAgent = null,
        private bool $autoReconnect = true,
        private int $reconnectInitialDelayMs = 500,
        private int $reconnectMaxDelayMs = 60000,
        private int $reconnectMaxAttempts = 0,
    ) {
        if ($connectionTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Connection timeout must be positive');
        }

        if ($reconnectInitialDelayMs <= 0) {
            throw new InvalidArgumentException('Reconnect initial delay must be positive');
        }

        if ($reconnectMaxDelayMs < $reconnectInitialDelayMs) {
            throw new InvalidArgumentException('Reconnect max delay must be at least the initial delay');
        }

        if ($reconnectMaxAttempts < 0) {
            throw new InvalidArgumentException('Reconnect max attempts must be zero or positive');
        }
    }

    public function getConnectionTimeoutSeconds(): int
    {
        return $this->connectionTimeoutSeconds;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isAutoReconnect(): bool
    {
        return $this->autoReconnect;
    }

    public function getReconnectInitialDelayMs(): int
    {
        return $this->reconnectInitialDelayMs;
    }

    public function getReconnectMaxDelayMs(): int
    {
        return $this->reconnectMaxDelayMs;
    }

    public function getReconnectMaxAttempts(): int
    {
        return $this->reconnectMaxAttempts;
    }

    public function baseBackoffMs(int $attempt): int
    {
        return (int) min($this->reconnectInitialDelayMs * (2 ** $attempt), $this->reconnectMaxDelayMs);
    }

    public function withConnectionTimeout(int $seconds): self
    {
        return new self(
            $seconds,
            $this->headers,
            $this->userAgent,
            $this->autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $this->reconnectMaxAttempts,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $headers,
            $this->userAgent,
            $this->autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $this->reconnectMaxAttempts,
        );
    }

    public function withUserAgent(string $userAgent): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $this->headers,
            $userAgent,
            $this->autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $this->reconnectMaxAttempts,
        );
    }

    public function withAutoReconnect(bool $autoReconnect): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $this->headers,
            $this->userAgent,
            $autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $this->reconnectMaxAttempts,
        );
    }

    public function withReconnectDelays(int $initialMs, int $maxMs): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $this->headers,
            $this->userAgent,
            $this->autoReconnect,
            $initialMs,
            $maxMs,
            $this->reconnectMaxAttempts,
        );
    }

    public function withReconnectMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $this->headers,
            $this->userAgent,
            $this->autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $maxAttempts,
        );
    }
}
