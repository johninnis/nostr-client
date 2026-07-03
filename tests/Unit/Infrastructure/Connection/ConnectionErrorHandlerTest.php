<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionErrorHandler;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySessionRegistry;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ConnectionErrorHandlerTest extends TestCase
{
    private const string RELAY = 'wss://relay.test';

    public function testFailTransitionsTheConnectionToFailed(): void
    {
        $registry = new RelaySessionRegistry();
        $this->store($registry, new ConnectionConfig());

        $this->handler($registry)->fail($this->relayUrl(), new RuntimeException('boom'));

        self::assertSame(ConnectionState::FAILED, $registry->find($this->relayUrl())?->getConnection()->getState());
    }

    public function testFailReturnsTheConfigToReconnectWithWhenAutoReconnectIsEnabled(): void
    {
        $registry = new RelaySessionRegistry();
        $config = new ConnectionConfig(autoReconnect: true);
        $this->store($registry, $config);

        $result = $this->handler($registry)->fail($this->relayUrl(), new RuntimeException('boom'));

        self::assertSame($config, $result);
    }

    public function testFailReturnsNullWhenAutoReconnectIsDisabled(): void
    {
        $registry = new RelaySessionRegistry();
        $this->store($registry, new ConnectionConfig(autoReconnect: false));

        $result = $this->handler($registry)->fail($this->relayUrl(), new RuntimeException('boom'));

        self::assertNull($result);
    }

    public function testFailIsANoOpForAStaleGeneration(): void
    {
        $registry = new RelaySessionRegistry();
        $this->store($registry, new ConnectionConfig());
        $registry->nextGeneration($this->relayUrl());

        $result = $this->handler($registry)->fail($this->relayUrl(), new RuntimeException('boom'), 0);

        self::assertNull($result);
        self::assertSame(ConnectionState::CONNECTED, $registry->find($this->relayUrl())?->getConnection()->getState());
    }

    public function testFailIsIdempotentOnAnAlreadyFailedConnection(): void
    {
        $registry = new RelaySessionRegistry();
        $this->store($registry, new ConnectionConfig());
        $handler = $this->handler($registry);
        $handler->fail($this->relayUrl(), new RuntimeException('boom'));

        $result = $handler->fail($this->relayUrl(), new RuntimeException('again'));

        self::assertNull($result);
    }

    private function handler(RelaySessionRegistry $registry): ConnectionErrorHandler
    {
        return new ConnectionErrorHandler($registry, new NullLogger());
    }

    private function store(RelaySessionRegistry $registry, ConnectionConfig $config): void
    {
        $connection = new RelayConnection($this->relayUrl(), ConnectionState::CONNECTED, $config);
        $registry->store($this->relayUrl(), new RelaySession($connection, new ScriptedWebsocketConnection()));
    }

    private function relayUrl(): RelayUrl
    {
        return RelayUrl::tryFromString(self::RELAY) ?? self::fail('invalid relay URL');
    }
}
