<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ConnectionFactory();
    }

    public function testConnectionToUnreachableRelayThrowsConnectionException(): void
    {
        $relayUrl = RelayUrl::fromString('wss://localhost:19999');
        self::assertNotNull($relayUrl);
        $config = new ConnectionConfig(connectionTimeoutSeconds: 1);

        $this->expectException(ConnectionException::class);

        $this->factory->createConnection($relayUrl, $config)->await();
    }

    public function testConnectionExceptionContainsRelayUrl(): void
    {
        $relayUrl = RelayUrl::fromString('wss://localhost:19999');
        self::assertNotNull($relayUrl);
        $config = new ConnectionConfig(connectionTimeoutSeconds: 1);

        try {
            $this->factory->createConnection($relayUrl, $config)->await();
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame($relayUrl, $e->getRelayUrl());
            $this->assertStringContainsString('wss://localhost:19999', $e->getMessage());
        }
    }

    public function testConnectionToInvalidSchemeThrowsConnectionException(): void
    {
        $relayUrl = RelayUrl::fromString('ws://localhost:19999');
        self::assertNotNull($relayUrl);
        $config = new ConnectionConfig(connectionTimeoutSeconds: 1);

        $this->expectException(ConnectionException::class);

        $this->factory->createConnection($relayUrl, $config)->await();
    }
}
