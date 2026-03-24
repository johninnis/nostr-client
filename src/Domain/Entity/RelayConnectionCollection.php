<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Entity;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

final readonly class RelayConnectionCollection implements IteratorAggregate, Countable
{
    private array $connections;

    public function __construct(array $connections = [])
    {
        foreach ($connections as $connection) {
            if (!$connection instanceof RelayConnection) {
                throw new InvalidArgumentException('All items must be RelayConnection instances');
            }
        }
        $this->connections = array_values($connections);
    }

    public function add(RelayConnection $connection): self
    {
        $connections = $this->connections;
        $connections[] = $connection;

        return new self($connections);
    }

    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->connections, $predicate));
    }

    public function map(callable $callback): array
    {
        return array_map($callback, $this->connections);
    }

    public function isEmpty(): bool
    {
        return empty($this->connections);
    }

    public function toArray(): array
    {
        return $this->connections;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->connections);
    }

    public function count(): int
    {
        return count($this->connections);
    }
}
