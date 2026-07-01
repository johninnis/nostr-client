<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Collection;

use Innis\Nostr\Client\Domain\Collection\HealthCheckResultCollection;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultCollectionTest extends TestCase
{
    private RelayUrl $relayUrl;

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
    }

    public function testEmptyCollection(): void
    {
        $collection = new HealthCheckResultCollection();

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
        $this->assertSame([], $collection->toArray());
    }

    public function testConstructorValidatesElementType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthCheckResultCollection(['not-a-result']);
    }

    public function testHoldsResults(): void
    {
        $result = HealthCheckResult::success($this->relayUrl);
        $collection = new HealthCheckResultCollection([$result]);

        $this->assertSame(1, $collection->count());
        $this->assertSame($result, $collection->toArray()[0]);
    }

    public function testIteration(): void
    {
        $relay2 = RelayUrl::fromString('wss://relay2.example.com');
        self::assertNotNull($relay2);
        $success = HealthCheckResult::success($this->relayUrl);
        $failure = HealthCheckResult::failure($relay2, 'Connection refused');
        $collection = new HealthCheckResultCollection([$success, $failure]);

        $items = [];
        foreach ($collection as $result) {
            $items[(string) $result->getRelayUrl()] = $result;
        }

        $this->assertSame($success, $items['wss://relay.example.com']);
        $this->assertSame($failure, $items['wss://relay2.example.com']);
    }
}
