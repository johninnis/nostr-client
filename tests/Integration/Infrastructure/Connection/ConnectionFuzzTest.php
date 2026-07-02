<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Amp\Websocket\Client\WebsocketConnection;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\SendFailingWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\SuppliedWebsocketConnector;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

/**
 * Drives the connection handler through randomised interleavings of every operation
 * against sockets that succeed, sockets that fail every send, and sockets dropped
 * mid-session. The connection state machine is where the concurrency invariants live,
 * so it is fuzzed directly rather than behind the guarding facade. The invariant: only
 * a ConnectionException may escape an operation — any other throwable (an illegal state
 * transition, an unhandled fault) fails the run, and the seed reproduces it.
 */
final class ConnectionFuzzTest extends TestCase
{
    private const int OPERATIONS_PER_RUN = 120;

    /**
     * @return list<array{int}>
     */
    public static function seeds(): array
    {
        return [[1], [2], [3], [5], [8], [13], [21], [42], [99], [123], [7777], [31337]];
    }

    #[DataProvider('seeds')]
    public function testRandomOperationInterleavingsOnlyEverLeakAConnectionException(int $seed): void
    {
        mt_srand($seed);

        $relay = $this->relayUrl();
        $sockets = [];
        $connector = new SuppliedWebsocketConnector(static function () use (&$sockets): WebsocketConnection {
            $socket = 1 === count($sockets) % 2
                ? new SendFailingWebsocketConnection()
                : new ScriptedWebsocketConnection();
            $sockets[] = $socket;

            return $socket;
        });
        $connection = new AmphpRelayConnection(new ConnectionFactory($connector), new JsonMessageDeserialiser());

        $operations = $this->operations();
        $lastSubscriptionId = null;

        for ($step = 0; $step < self::OPERATIONS_PER_RUN; ++$step) {
            $operation = $operations[mt_rand(0, count($operations) - 1)];

            try {
                match ($operation) {
                    'connect' => $connection->connect($relay, new ConnectionConfig(
                        autoReconnect: 1 === mt_rand(0, 1),
                        reconnectInitialDelayMs: 1,
                        reconnectMaxDelayMs: 4,
                        reconnectMaxAttempts: 3,
                    )),
                    'disconnect' => $connection->disconnect($relay),
                    'subscribe' => $connection->subscribe($relay, $lastSubscriptionId = SubscriptionId::generate(), new Filter(), $this->noopHandler()),
                    'unsubscribe' => null !== $lastSubscriptionId ? $connection->unsubscribe($relay, $lastSubscriptionId) : null,
                    'publish' => $connection->publishEvent($relay, EventMother::textNote())->ignore(),
                    'ping' => $connection->ping($relay),
                    'drop' => $this->dropRandomSocket($sockets),
                    default => null,
                };
            } catch (ConnectionException) {
                continue;
            } finally {
                delay(0);
            }
        }

        $connection->disconnect($relay);
        for ($settle = 0; $settle < 5 && $connection->isConnected($relay); ++$settle) {
            $connection->disconnect($relay);
            delay(0.005);
        }

        self::assertFalse($connection->isConnected($relay), "seed {$seed} left the relay connected after teardown");
    }

    /**
     * @return list<string>
     */
    private function operations(): array
    {
        return ['connect', 'disconnect', 'subscribe', 'unsubscribe', 'publish', 'ping', 'drop'];
    }

    /**
     * @param list<WebsocketConnection> $sockets
     */
    private function dropRandomSocket(array $sockets): void
    {
        if ([] === $sockets) {
            return;
        }

        $sockets[mt_rand(0, count($sockets) - 1)]->close();
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }

    private function noopHandler(): EventHandlerInterface
    {
        return new class implements EventHandlerInterface {
            #[Override]
            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
            }

            #[Override]
            public function handleEose(SubscriptionId $subscriptionId): void
            {
            }

            #[Override]
            public function handleClosed(SubscriptionId $subscriptionId, string $message): void
            {
            }

            #[Override]
            public function handleNotice(RelayUrl $relayUrl, string $message): void
            {
            }
        };
    }
}
