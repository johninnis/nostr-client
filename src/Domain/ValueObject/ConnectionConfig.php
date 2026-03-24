<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ConnectionConfig
{
    public function __construct(
        private int $connectionTimeoutSeconds = 10,
        private array $headers = [],
        private ?string $userAgent = null,
    ) {
        if ($connectionTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('Connection timeout must be positive');
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

    public function withConnectionTimeout(int $seconds): self
    {
        return new self($seconds, $this->headers, $this->userAgent);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->connectionTimeoutSeconds, $headers, $this->userAgent);
    }

    public function withUserAgent(string $userAgent): self
    {
        return new self($this->connectionTimeoutSeconds, $this->headers, $userAgent);
    }
}
