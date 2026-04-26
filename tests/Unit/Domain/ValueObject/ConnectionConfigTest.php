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
        $this->assertNull($config->getOnReconnected());
    }

    public function testConstructorWithCustomValues(): void
    {
        $callback = static function (): void {};

        $config = new ConnectionConfig(
            connectionTimeoutSeconds: 30,
            headers: ['X-Custom' => 'value'],
            userAgent: 'TestAgent/1.0',
            autoReconnect: false,
            reconnectInitialDelayMs: 100,
            reconnectMaxDelayMs: 5000,
            reconnectMaxAttempts: 5,
            onReconnected: $callback,
        );

        $this->assertSame(30, $config->getConnectionTimeoutSeconds());
        $this->assertSame(['X-Custom' => 'value'], $config->getHeaders());
        $this->assertSame('TestAgent/1.0', $config->getUserAgent());
        $this->assertFalse($config->isAutoReconnect());
        $this->assertSame(100, $config->getReconnectInitialDelayMs());
        $this->assertSame(5000, $config->getReconnectMaxDelayMs());
        $this->assertSame(5, $config->getReconnectMaxAttempts());
        $this->assertSame($callback, $config->getOnReconnected());
    }

    #[DataProvider('invalidValueProvider')]
    public function testConstructorThrowsOnInvalidValues(array $params, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ConnectionConfig(...$params);
    }

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

    public function testWithConnectionTimeout(): void
    {
        $original = new ConnectionConfig();
        $modified = $original->withConnectionTimeout(20);

        $this->assertSame(10, $original->getConnectionTimeoutSeconds());
        $this->assertSame(20, $modified->getConnectionTimeoutSeconds());
        $this->assertSame($original->getHeaders(), $modified->getHeaders());
        $this->assertSame($original->getUserAgent(), $modified->getUserAgent());
    }

    public function testWithHeaders(): void
    {
        $original = new ConnectionConfig();
        $headers = ['Authorization' => 'Bearer token', 'X-Custom' => 'value'];
        $modified = $original->withHeaders($headers);

        $this->assertEmpty($original->getHeaders());
        $this->assertSame($headers, $modified->getHeaders());
        $this->assertSame($original->getConnectionTimeoutSeconds(), $modified->getConnectionTimeoutSeconds());
    }

    public function testWithUserAgent(): void
    {
        $original = new ConnectionConfig();
        $modified = $original->withUserAgent('NostrClient/1.0');

        $this->assertNull($original->getUserAgent());
        $this->assertSame('NostrClient/1.0', $modified->getUserAgent());
        $this->assertSame($original->getConnectionTimeoutSeconds(), $modified->getConnectionTimeoutSeconds());
        $this->assertSame($original->getHeaders(), $modified->getHeaders());
    }

    public function testWithAutoReconnect(): void
    {
        $original = new ConnectionConfig();
        $modified = $original->withAutoReconnect(false);

        $this->assertTrue($original->isAutoReconnect());
        $this->assertFalse($modified->isAutoReconnect());
    }

    public function testWithReconnectDelays(): void
    {
        $original = new ConnectionConfig();
        $modified = $original->withReconnectDelays(250, 10000);

        $this->assertSame(500, $original->getReconnectInitialDelayMs());
        $this->assertSame(60000, $original->getReconnectMaxDelayMs());
        $this->assertSame(250, $modified->getReconnectInitialDelayMs());
        $this->assertSame(10000, $modified->getReconnectMaxDelayMs());
    }

    public function testWithReconnectMaxAttempts(): void
    {
        $original = new ConnectionConfig();
        $modified = $original->withReconnectMaxAttempts(3);

        $this->assertSame(0, $original->getReconnectMaxAttempts());
        $this->assertSame(3, $modified->getReconnectMaxAttempts());
    }

    public function testWithOnReconnected(): void
    {
        $original = new ConnectionConfig();
        $callback = static function (): void {};
        $modified = $original->withOnReconnected($callback);

        $this->assertNull($original->getOnReconnected());
        $this->assertSame($callback, $modified->getOnReconnected());
    }

    public function testWithOnReconnectedAcceptsNullToClear(): void
    {
        $callback = static function (): void {};
        $original = (new ConnectionConfig())->withOnReconnected($callback);
        $modified = $original->withOnReconnected(null);

        $this->assertSame($callback, $original->getOnReconnected());
        $this->assertNull($modified->getOnReconnected());
    }
}
