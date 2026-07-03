<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\ProgrammableWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\RecordingReconnectionListener;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\Service\JsonMessageDeserialiser;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\delay;

final class AmphpRelayConnectionReconnectTest extends TestCase
{
    public function testReconnectRetriesAfterTransientFailuresAndFiresListenerOnlyOnSuccess(): void
    {
        $relayUrl = $this->relayUrl();
        $first = new ScriptedWebsocketConnection();
        $restored = new ScriptedWebsocketConnection();
        $connector = new ProgrammableWebsocketConnector([
            $first,
            new RuntimeException('relay down'),
            new RuntimeException('relay down'),
            $restored,
        ]);
        $connection = $this->connection($connector);
        $listener = new RecordingReconnectionListener();
        $connection->setReconnectionListener($listener);

        $connection->connect($relayUrl, $this->reconnectConfig(maxAttempts: 0));
        delay(0.01);
        $first->endStream();

        $listener->awaitFirstReconnect(2.0);

        self::assertSame([(string) $relayUrl], $listener->reconnectedRelays, 'listener fires once, only on the successful reconnect');
        self::assertSame(4, $connector->connectCount, '1 initial connect + 3 reconnect attempts (2 failed, 1 succeeded)');
        self::assertTrue($connection->isConnected($relayUrl));

        $restored->endStream();
        $connection->disconnect($relayUrl);
    }

    public function testReconnectGivesUpAfterMaxAttemptsWithoutFiringListener(): void
    {
        $relayUrl = $this->relayUrl();
        $first = new ScriptedWebsocketConnection();
        $connector = new ProgrammableWebsocketConnector([
            $first,
            new RuntimeException('relay down'),
            new RuntimeException('relay down'),
        ]);
        $connection = $this->connection($connector);
        $listener = new RecordingReconnectionListener();
        $connection->setReconnectionListener($listener);

        $connection->connect($relayUrl, $this->reconnectConfig(maxAttempts: 2));
        delay(0.01);
        $first->endStream();

        delay(0.3);

        self::assertSame([], $listener->reconnectedRelays, 'listener never fires when every attempt fails');
        self::assertSame(3, $connector->connectCount, '1 initial connect + exactly 2 bounded reconnect attempts');
        self::assertFalse($connection->isConnected($relayUrl));
        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

        $connection->disconnect($relayUrl);
    }

    public function testReconnectWaitsTheBackoffDelayBeforeRetrying(): void
    {
        $relayUrl = $this->relayUrl();
        $first = new ScriptedWebsocketConnection();
        $restored = new ScriptedWebsocketConnection();
        $connector = new ProgrammableWebsocketConnector([$first, $restored]);
        $connection = $this->connection($connector);
        $listener = new RecordingReconnectionListener();
        $connection->setReconnectionListener($listener);

        $connection->connect($relayUrl, $this->reconnectConfig(maxAttempts: 0, initialDelayMs: 100));
        delay(0.01);

        $start = microtime(true);
        $first->endStream();
        $listener->awaitFirstReconnect(2.0);
        $elapsed = microtime(true) - $start;

        self::assertGreaterThanOrEqual(0.09, $elapsed, 'the first reconnect must wait at least the base backoff delay');
        self::assertLessThan(0.5, $elapsed, 'the delay stays bounded (base plus up to 25% jitter), not capped at the max');

        $restored->endStream();
        $connection->disconnect($relayUrl);
    }

    public function testNoReconnectIsScheduledWhenAutoReconnectIsDisabled(): void
    {
        $relayUrl = $this->relayUrl();
        $first = new ScriptedWebsocketConnection();
        // Only the initial socket is scripted: any reconnect attempt would call connect() again.
        $connector = new ProgrammableWebsocketConnector([$first]);
        $connection = $this->connection($connector);
        $listener = new RecordingReconnectionListener();
        $connection->setReconnectionListener($listener);

        $connection->connect($relayUrl, new ConnectionConfig(
            autoReconnect: false,
            reconnectInitialDelayMs: 10,
            reconnectMaxDelayMs: 1000,
        ));
        delay(0.01);
        $first->endStream();

        delay(0.2);

        self::assertSame(1, $connector->connectCount, 'a dropped connection must not be reconnected when auto-reconnect is disabled');
        self::assertSame([], $listener->reconnectedRelays);
        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

        $connection->disconnect($relayUrl);
    }

    private function reconnectConfig(int $maxAttempts, int $initialDelayMs = 10): ConnectionConfig
    {
        return new ConnectionConfig(
            autoReconnect: true,
            reconnectInitialDelayMs: $initialDelayMs,
            reconnectMaxDelayMs: 1000,
            reconnectMaxAttempts: $maxAttempts,
        );
    }

    private function connection(ProgrammableWebsocketConnector $connector): AmphpRelayConnection
    {
        return new AmphpRelayConnection(
            new ConnectionFactory($connector),
            new JsonMessageDeserialiser(),
        );
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }
}
