<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $config = new ConnectionConfig();

        $this->assertSame(10, $config->getConnectionTimeoutSeconds());
        $this->assertEmpty($config->getHeaders());
        $this->assertNull($config->getUserAgent());
        $this->assertTrue($config->isAutoReconnect());
        $this->assertSame(500, $config->getReconnectInitialDelayMs());
        $this->assertSame(60000, $config->getReconnectMaxDelayMs());
        $this->assertSame(0, $config->getReconnectMaxAttempts());
    }

    public function testConstructorWithCustomValues(): void
    {
        $config = new ConnectionConfig(
            connectionTimeoutSeconds: 30,
            headers: ['X-Custom' => 'value'],
            userAgent: 'TestAgent/1.0',
            autoReconnect: false,
            reconnectInitialDelayMs: 100,
            reconnectMaxDelayMs: 5000,
            reconnectMaxAttempts: 5,
            heartbeatIntervalMs: 45000,
        );

        $this->assertSame(30, $config->getConnectionTimeoutSeconds());
        $this->assertSame(['X-Custom' => 'value'], $config->getHeaders());
        $this->assertSame('TestAgent/1.0', $config->getUserAgent());
        $this->assertFalse($config->isAutoReconnect());
        $this->assertSame(100, $config->getReconnectInitialDelayMs());
        $this->assertSame(5000, $config->getReconnectMaxDelayMs());
        $this->assertSame(5, $config->getReconnectMaxAttempts());
        $this->assertSame(45000, $config->getHeartbeatIntervalMs());
    }

    /**
     * @param array<string, int> $params
     */
    #[DataProvider('invalidValueProvider')]
    public function testConstructorThrowsOnInvalidValues(array $params, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ConnectionConfig(...$params);
    }

    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'negative connection timeout' => [
                ['connectionTimeoutSeconds' => -1],
                'Connection timeout must be positive',
            ],
            'zero connection timeout' => [
                ['connectionTimeoutSeconds' => 0],
                'Connection timeout must be positive',
            ],
            'zero reconnect initial delay' => [
                ['reconnectInitialDelayMs' => 0],
                'Reconnect initial delay must be positive',
            ],
            'negative reconnect initial delay' => [
                ['reconnectInitialDelayMs' => -1],
                'Reconnect initial delay must be positive',
            ],
            'reconnect max delay below initial' => [
                ['reconnectInitialDelayMs' => 1000, 'reconnectMaxDelayMs' => 500],
                'Reconnect max delay must be at least the initial delay',
            ],
            'negative reconnect max attempts' => [
                ['reconnectMaxAttempts' => -1],
                'Reconnect max attempts must be zero or positive',
            ],
        ];
    }

    public function testBaseBackoffGrowsExponentially(): void
    {
        $config = new ConnectionConfig(reconnectInitialDelayMs: 100, reconnectMaxDelayMs: 60000);

        $this->assertSame(100, $config->baseBackoffMs(0));
        $this->assertSame(200, $config->baseBackoffMs(1));
        $this->assertSame(400, $config->baseBackoffMs(2));
        $this->assertSame(800, $config->baseBackoffMs(3));
    }

    public function testBaseBackoffIsCappedAtTheMaxDelay(): void
    {
        $config = new ConnectionConfig(reconnectInitialDelayMs: 100, reconnectMaxDelayMs: 500);

        $this->assertSame(400, $config->baseBackoffMs(2));
        $this->assertSame(500, $config->baseBackoffMs(3));
        $this->assertSame(500, $config->baseBackoffMs(4));
        $this->assertSame(500, $config->baseBackoffMs(1000));
    }
}
