<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Factory;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class NostrClientFactoryTest extends TestCase
{
    public function testCreatedClientReportsUnknownRelayAsDisconnected(): void
    {
        $client = NostrClientFactory::create();
        $relay = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relay);

        $this->assertSame(ConnectionState::DISCONNECTED, $client->getConnectionStatus($relay));
    }

    public function testCreatedClientHasNoConnections(): void
    {
        $client = NostrClientFactory::create();

        $this->assertTrue($client->getConnectedRelays()->isEmpty());
    }
}
