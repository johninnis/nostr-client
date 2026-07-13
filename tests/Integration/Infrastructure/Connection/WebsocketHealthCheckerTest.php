<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Infrastructure\Connection\WebsocketHealthChecker;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class WebsocketHealthCheckerTest extends TestCase
{
    private WebsocketHealthChecker $healthChecker;

    protected function setUp(): void
    {
        $this->healthChecker = new WebsocketHealthChecker(new ConnectionFactory());
    }

    public function testUnreachableRelayReturnsFailure(): void
    {
        $relayUrl = RelayUrl::tryFromString('wss://localhost:19999');
        self::assertNotNull($relayUrl);

        $result = $this->healthChecker->checkHealth($relayUrl, 1.0);

        $this->assertFalse($result->isHealthy());
        $this->assertNotNull($result->getErrorMessage());
    }

    public function testHealthCheckWithShortTimeoutReturnsFailure(): void
    {
        $relayUrl = RelayUrl::tryFromString('wss://localhost:19999');
        self::assertNotNull($relayUrl);

        $result = $this->healthChecker->checkHealth($relayUrl, 0.1);

        $this->assertFalse($result->isHealthy());
        $this->assertNotNull($result->getErrorMessage());
    }
}
