<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Factory;

use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use PHPUnit\Framework\TestCase;

final class NostrClientFactoryTest extends TestCase
{
    public function testCreateWithDefaultConfig(): void
    {
        $client = NostrClientFactory::create();

        $this->assertInstanceOf(NostrClientInterface::class, $client);
    }

    public function testCreatedClientHasNoConnections(): void
    {
        $client = NostrClientFactory::create();

        $this->assertTrue($client->getConnectedRelays()->isEmpty());
    }
}
