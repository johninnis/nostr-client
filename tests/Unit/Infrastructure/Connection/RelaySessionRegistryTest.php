<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Amp\DeferredCancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySessionRegistry;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class RelaySessionRegistryTest extends TestCase
{
    public function testFindReturnsNullForAnUnknownRelay(): void
    {
        $registry = new RelaySessionRegistry();

        self::assertNull($registry->find($this->relayUrl('wss://relay.one')));
    }

    public function testAStoredSessionIsFoundByItsRelay(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');
        $session = $this->session($relayUrl);

        $registry->store($relayUrl, $session);

        self::assertSame($session, $registry->find($relayUrl));
    }

    public function testRemoveDropsTheSession(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');
        $registry->store($relayUrl, $this->session($relayUrl));

        $registry->remove($relayUrl);

        self::assertNull($registry->find($relayUrl));
    }

    public function testAllReturnsEveryStoredSession(): void
    {
        $registry = new RelaySessionRegistry();
        $one = $this->relayUrl('wss://relay.one');
        $two = $this->relayUrl('wss://relay.two');
        $sessionOne = $this->session($one);
        $sessionTwo = $this->session($two);

        $registry->store($one, $sessionOne);
        $registry->store($two, $sessionTwo);

        self::assertEqualsCanonicalizing([$sessionOne, $sessionTwo], $registry->all());
    }

    public function testGenerationStartsAtZero(): void
    {
        $registry = new RelaySessionRegistry();

        self::assertSame(0, $registry->generation($this->relayUrl('wss://relay.one')));
    }

    public function testNextGenerationIncrementsAndPersists(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');

        self::assertSame(1, $registry->nextGeneration($relayUrl));
        self::assertSame(2, $registry->nextGeneration($relayUrl));
        self::assertSame(2, $registry->generation($relayUrl));
    }

    public function testGenerationsAreIndependentPerRelay(): void
    {
        $registry = new RelaySessionRegistry();
        $one = $this->relayUrl('wss://relay.one');
        $two = $this->relayUrl('wss://relay.two');

        $registry->nextGeneration($one);

        self::assertSame(0, $registry->generation($two));
    }

    public function testReconnectIsAbsentInitially(): void
    {
        $registry = new RelaySessionRegistry();

        self::assertFalse($registry->hasReconnect($this->relayUrl('wss://relay.one')));
    }

    public function testBeginReconnectMarksTheRelay(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');

        $registry->beginReconnect($relayUrl, new DeferredCancellation());

        self::assertTrue($registry->hasReconnect($relayUrl));
    }

    public function testEndReconnectClearsTheRelay(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');
        $registry->beginReconnect($relayUrl, new DeferredCancellation());

        $registry->endReconnect($relayUrl);

        self::assertFalse($registry->hasReconnect($relayUrl));
    }

    public function testCancelReconnectCancelsTheDeferredAndClearsTheRelay(): void
    {
        $registry = new RelaySessionRegistry();
        $relayUrl = $this->relayUrl('wss://relay.one');
        $deferred = new DeferredCancellation();
        $registry->beginReconnect($relayUrl, $deferred);

        $registry->cancelReconnect($relayUrl);

        self::assertTrue($deferred->getCancellation()->isRequested());
        self::assertFalse($registry->hasReconnect($relayUrl));
    }

    private function relayUrl(string $url): RelayUrl
    {
        return RelayUrl::fromString($url) ?? self::fail('invalid relay URL');
    }

    private function session(RelayUrl $relayUrl): RelaySession
    {
        $connection = new RelayConnection($relayUrl, ConnectionState::CONNECTED, new ConnectionConfig());

        return new RelaySession($connection, $this->createStub(WebsocketConnection::class));
    }
}
