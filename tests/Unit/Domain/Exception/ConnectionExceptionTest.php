<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Exception;

use Exception;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;

final class ConnectionExceptionTest extends TestCase
{
    private RelayUrl $relayUrl;

    protected function setUp(): void
    {
        $relayUrl = RelayUrl::fromString('wss://relay.example.com');
        self::assertNotNull($relayUrl);
        $this->relayUrl = $relayUrl;
    }

    public function testBasicConstructor(): void
    {
        $exception = new ConnectionException('Test message', 123);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(123, $exception->getCode());
        $this->assertNull($exception->getRelayUrl());
        $this->assertNull($exception->getPrevious());
    }

    public function testConstructorWithRelayUrl(): void
    {
        $previous = new Exception('Previous exception');
        $exception = new ConnectionException('Test message', 456, $previous, $this->relayUrl);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame(456, $exception->getCode());
        $this->assertSame($this->relayUrl, $exception->getRelayUrl());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testForRelay(): void
    {
        $previous = new Exception('Original error');
        $exception = ConnectionException::forRelay($this->relayUrl, 'Connection failed', $previous);

        $this->assertStringContainsString('Connection error for relay wss://relay.example.com', $exception->getMessage());
        $this->assertStringContainsString('Connection failed', $exception->getMessage());
        $this->assertSame($this->relayUrl, $exception->getRelayUrl());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testTimeout(): void
    {
        $exception = ConnectionException::timeout($this->relayUrl, 30);

        $this->assertStringContainsString('Connection timeout after 30s', $exception->getMessage());
        $this->assertStringContainsString('wss://relay.example.com', $exception->getMessage());
        $this->assertSame(408, $exception->getCode());
        $this->assertSame($this->relayUrl, $exception->getRelayUrl());
    }

    public function testRefused(): void
    {
        $exception = ConnectionException::refused($this->relayUrl);

        $this->assertStringContainsString('Connection refused by relay', $exception->getMessage());
        $this->assertStringContainsString('wss://relay.example.com', $exception->getMessage());
        $this->assertSame(61, $exception->getCode());
        $this->assertSame($this->relayUrl, $exception->getRelayUrl());
    }

    public function testUnauthorised(): void
    {
        $exception = ConnectionException::unauthorised($this->relayUrl);

        $this->assertStringContainsString('Unauthorised connection to relay', $exception->getMessage());
        $this->assertStringContainsString('wss://relay.example.com', $exception->getMessage());
        $this->assertSame(401, $exception->getCode());
        $this->assertSame($this->relayUrl, $exception->getRelayUrl());
    }
}
