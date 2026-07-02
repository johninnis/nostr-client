<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Amp\DeferredFuture;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage as RelayEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use Override;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class AmphpRelayConnectionDispatchTest extends TestCase
{
    public function testDeliversFramesInReceiptOrderEvenWhenHandlersSuspend(): void
    {
        $relayUrl = $this->relayUrl();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $handler = new class implements EventHandlerInterface {
            /** @var list<string> */
            public array $received = [];

            private int $call = 0;

            #[Override]
            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
                // Earlier frames sleep longer: under any concurrent dispatch a later, shorter-sleeping
                // frame overtakes and is recorded first. A single ordered fiber preserves receipt order.
                $delays = [0.05, 0.03, 0.01];
                $sleep = $delays[$this->call] ?? 0.0;
                ++$this->call;
                delay($sleep);
                $this->received[] = (string) $event->getContent();
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

        $subscriptionId = SubscriptionId::fromString('sub-order');
        self::assertNotNull($subscriptionId);
        $connection->subscribe($relayUrl, $subscriptionId, $this->kindOneFilter(), $handler);

        $ws->pushInbound($this->eventFrame('sub-order', 1));
        $ws->pushInbound($this->eventFrame('sub-order', 2));
        $ws->pushInbound($this->eventFrame('sub-order', 3));

        delay(0.3);

        self::assertSame(['msg-1', 'msg-2', 'msg-3'], $handler->received);

        $connection->disconnect($relayUrl);
    }

    public function testExceedingTheInboundBacklogFailsTheConnection(): void
    {
        $relayUrl = $this->relayUrl();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        /** @var DeferredFuture<null> $gate */
        $gate = new DeferredFuture();
        $handler = new class($gate) implements EventHandlerInterface {
            /**
             * @param DeferredFuture<null> $gate
             */
            public function __construct(private readonly DeferredFuture $gate)
            {
            }

            #[Override]
            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
                $this->gate->getFuture()->await();
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

        $subscriptionId = SubscriptionId::fromString('sub-flood');
        self::assertNotNull($subscriptionId);
        $connection->subscribe($relayUrl, $subscriptionId, $this->kindOneFilter(), $handler);

        // The first frame wedges the single dispatch fiber on the gate; the rest pile up behind it.
        // Once the connection fails the reader stops consuming, so the flood runs off the test fiber
        // to avoid a blocked push.
        async(function () use ($ws): void {
            for ($seq = 1; $seq <= 1100; ++$seq) {
                $ws->pushInbound($this->eventFrame('sub-flood', $seq));
            }
        })->ignore();

        delay(0.5);

        $failed = $connection->getConnection($relayUrl);
        self::assertNotNull($failed);
        self::assertSame(ConnectionState::FAILED, $failed->getState(), 'a consumer that falls too far behind fails the connection rather than growing memory unbounded');

        $gate->complete(null);
        $connection->disconnect($relayUrl);
    }

    private function connect(ScriptedWebsocketConnection $ws, RelayUrl $relayUrl): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
            new JsonMessageDeserialiser(),
        );

        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));
        delay(0.01);

        return $connection;
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::fromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }

    private function kindOneFilter(): Filter
    {
        $filter = Filter::fromArray(['kinds' => [1]]);
        self::assertNotNull($filter);

        return $filter;
    }

    private function eventFrame(string $subscriptionId, int $seq): string
    {
        $subId = SubscriptionId::fromString($subscriptionId);
        self::assertNotNull($subId);

        return new RelayEventMessage($subId, EventMother::textNote('msg-'.$seq))->toJson();
    }
}
