<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Collection;

use Innis\Nostr\Client\Domain\Collection\RelayConnectionCollection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RelayConnectionCollectionTest extends TestCase
{
    private RelayConnection $connection;

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->connection = new RelayConnection(
            $relayUrl,
            ConnectionState::CONNECTED,
            new ConnectionConfig(),
        );
    }

    public function testEmptyCollection(): void
    {
        $collection = new RelayConnectionCollection();

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
        $this->assertSame([], $collection->toArray());
    }

    public function testConstructorValidatesItems(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RelayConnectionCollection(['not-a-connection']);
    }

    public function testFilter(): void
    {
        $healthy = $this->connection;
        $relay2 = RelayUrl::tryFromString('wss://relay2.example.com');
        self::assertNotNull($relay2);
        $unhealthy = new RelayConnection(
            $relay2,
            ConnectionState::DISCONNECTED,
            new ConnectionConfig(),
        );

        $collection = new RelayConnectionCollection([$healthy, $unhealthy]);
        $filtered = $collection->filter(static fn (RelayConnection $c) => $c->isHealthy());

        $this->assertSame(2, $collection->count());
        $this->assertSame(1, $filtered->count());
        $this->assertSame($healthy, $filtered->toArray()[0]);
    }

    public function testIteration(): void
    {
        $collection = new RelayConnectionCollection([$this->connection]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        $this->assertSame([$this->connection], $items);
    }
}
