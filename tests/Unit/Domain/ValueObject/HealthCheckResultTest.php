<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultTest extends TestCase
{
    public function testSuccessCreatesHealthyResult(): void
    {
        $result = HealthCheckResult::success(42.5);

        $this->assertTrue($result->isHealthy());
        $this->assertSame(42.5, $result->getLatencyMs());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailureCreatesUnhealthyResult(): void
    {
        $result = HealthCheckResult::failure('Connection refused');

        $this->assertFalse($result->isHealthy());
        $this->assertNull($result->getLatencyMs());
        $this->assertSame('Connection refused', $result->getErrorMessage());
    }
}
