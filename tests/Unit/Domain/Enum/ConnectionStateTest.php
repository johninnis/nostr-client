<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Enum;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionStateTest extends TestCase
{
    public function testEnumValues(): void
    {
        $this->assertSame('disconnected', ConnectionState::DISCONNECTED->value);
        $this->assertSame('connected', ConnectionState::CONNECTED->value);
        $this->assertSame('disconnecting', ConnectionState::DISCONNECTING->value);
        $this->assertSame('failed', ConnectionState::FAILED->value);
    }

    public function testIsConnected(): void
    {
        $this->assertTrue(ConnectionState::CONNECTED->isConnected());
        $this->assertFalse(ConnectionState::DISCONNECTED->isConnected());
        $this->assertFalse(ConnectionState::DISCONNECTING->isConnected());
        $this->assertFalse(ConnectionState::FAILED->isConnected());
    }

    #[DataProvider('validTransitionProvider')]
    public function testValidTransitions(ConnectionState $from, ConnectionState $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public static function validTransitionProvider(): array
    {
        return [
            'disconnected to connected' => [ConnectionState::DISCONNECTED, ConnectionState::CONNECTED],
            'connected to disconnecting' => [ConnectionState::CONNECTED, ConnectionState::DISCONNECTING],
            'connected to failed' => [ConnectionState::CONNECTED, ConnectionState::FAILED],
            'disconnecting to disconnected' => [ConnectionState::DISCONNECTING, ConnectionState::DISCONNECTED],
            'disconnecting to failed' => [ConnectionState::DISCONNECTING, ConnectionState::FAILED],
            'failed to connected' => [ConnectionState::FAILED, ConnectionState::CONNECTED],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function testInvalidTransitions(ConnectionState $from, ConnectionState $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'disconnected to disconnecting' => [ConnectionState::DISCONNECTED, ConnectionState::DISCONNECTING],
            'disconnected to failed' => [ConnectionState::DISCONNECTED, ConnectionState::FAILED],
            'connected to disconnected' => [ConnectionState::CONNECTED, ConnectionState::DISCONNECTED],
            'disconnecting to connected' => [ConnectionState::DISCONNECTING, ConnectionState::CONNECTED],
            'failed to disconnecting' => [ConnectionState::FAILED, ConnectionState::DISCONNECTING],
            'failed to disconnected' => [ConnectionState::FAILED, ConnectionState::DISCONNECTED],
            'self transition disconnected' => [ConnectionState::DISCONNECTED, ConnectionState::DISCONNECTED],
            'self transition connected' => [ConnectionState::CONNECTED, ConnectionState::CONNECTED],
        ];
    }
}
