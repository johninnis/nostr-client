<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

final readonly class HealthCheckResultCollection implements IteratorAggregate, Countable
{
    private array $results;

    public function __construct(array $results = [])
    {
        foreach ($results as $key => $result) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be relay URL strings');
            }
            if (!$result instanceof HealthCheckResult) {
                throw new InvalidArgumentException('All items must be HealthCheckResult instances');
            }
        }
        $this->results = $results;
    }

    public function add(string $relayUrl, HealthCheckResult $result): self
    {
        $results = $this->results;
        $results[$relayUrl] = $result;

        return new self($results);
    }

    public function get(string $relayUrl): ?HealthCheckResult
    {
        return $this->results[$relayUrl] ?? null;
    }

    public function has(string $relayUrl): bool
    {
        return isset($this->results[$relayUrl]);
    }

    public function isEmpty(): bool
    {
        return empty($this->results);
    }

    public function toArray(): array
    {
        return $this->results;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->results);
    }

    public function count(): int
    {
        return count($this->results);
    }
}
