<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultTest extends TestCase
{
    private RelayUrl $relayUrl;

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
    }

    public function testSuccessCreatesHealthyResult(): void
    {
        $result = HealthCheckResult::success($this->relayUrl, 42.5);

        $this->assertSame($this->relayUrl, $result->getRelayUrl());
        $this->assertTrue($result->isHealthy());
        $this->assertSame(42.5, $result->getLatencyMs());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailureCreatesUnhealthyResult(): void
    {
        $result = HealthCheckResult::failure($this->relayUrl, 'Connection refused');

        $this->assertSame($this->relayUrl, $result->getRelayUrl());
        $this->assertFalse($result->isHealthy());
        $this->assertNull($result->getLatencyMs());
        $this->assertSame('Connection refused', $result->getErrorMessage());
    }
}
