<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResultCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultCollectionTest extends TestCase
{
    public function testEmptyCollection(): void
    {
        $collection = new HealthCheckResultCollection();

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
        $this->assertSame([], $collection->toArray());
    }

    public function testConstructorValidatesValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthCheckResultCollection(['wss://relay.example.com' => 'not-a-result']);
    }

    public function testConstructorValidatesKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HealthCheckResultCollection([0 => HealthCheckResult::success(10.0)]);
    }

    public function testAddReturnsNewCollection(): void
    {
        $collection = new HealthCheckResultCollection();
        $result = HealthCheckResult::success(42.5);

        $newCollection = $collection->add('wss://relay.example.com', $result);

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(1, $newCollection->count());
    }

    public function testGetAndHas(): void
    {
        $result = HealthCheckResult::success(42.5);
        $collection = new HealthCheckResultCollection(['wss://relay.example.com' => $result]);

        $this->assertTrue($collection->has('wss://relay.example.com'));
        $this->assertFalse($collection->has('wss://unknown.com'));
        $this->assertSame($result, $collection->get('wss://relay.example.com'));
        $this->assertNull($collection->get('wss://unknown.com'));
    }

    public function testIteration(): void
    {
        $success = HealthCheckResult::success(10.0);
        $failure = HealthCheckResult::failure('Connection refused');
        $collection = new HealthCheckResultCollection([
            'wss://relay1.example.com' => $success,
            'wss://relay2.example.com' => $failure,
        ]);

        $items = [];
        foreach ($collection as $key => $value) {
            $items[$key] = $value;
        }

        $this->assertSame($success, $items['wss://relay1.example.com']);
        $this->assertSame($failure, $items['wss://relay2.example.com']);
    }
}
