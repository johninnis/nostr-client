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
    }

    public function testConstructorWithCustomValues(): void
    {
        $config = new ConnectionConfig(
            connectionTimeoutSeconds: 30,
            headers: ['X-Custom' => 'value'],
            userAgent: 'TestAgent/1.0',
        );

        $this->assertSame(30, $config->getConnectionTimeoutSeconds());
        $this->assertSame(['X-Custom' => 'value'], $config->getHeaders());
        $this->assertSame('TestAgent/1.0', $config->getUserAgent());
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
}
