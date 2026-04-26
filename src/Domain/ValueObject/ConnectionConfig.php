<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Closure;
use InvalidArgumentException;

final readonly class ConnectionConfig
{
    public function __construct(
        private int $connectionTimeoutSeconds = 10,
        private array $headers = [],
        private ?string $userAgent = null,
        private bool $autoReconnect = true,
        private int $reconnectInitialDelayMs = 500,
        private int $reconnectMaxDelayMs = 60000,
        private int $reconnectMaxAttempts = 0,
        private ?Closure $onReconnected = null,
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

    public function getOnReconnected(): ?Closure
    {
        return $this->onReconnected;
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
            $this->onReconnected,
        );
    }

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
            $this->onReconnected,
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
            $this->onReconnected,
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
            $this->onReconnected,
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
            $this->onReconnected,
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
            $this->onReconnected,
        );
    }

    public function withOnReconnected(?Closure $onReconnected): self
    {
        return new self(
            $this->connectionTimeoutSeconds,
            $this->headers,
            $this->userAgent,
            $this->autoReconnect,
            $this->reconnectInitialDelayMs,
            $this->reconnectMaxDelayMs,
            $this->reconnectMaxAttempts,
            $onReconnected,
        );
    }
}
