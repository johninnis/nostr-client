<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\SendFailingWebsocketConnection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class AmphpRelayConnectionPublishTest extends TestCase
{
    public function testPublishResolvesAcceptedWhenTheRelayStoresTheEvent(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,"stored"]', $event->getId()->toHex()));

        $result = $future->await();

        self::assertTrue($result->isAccepted());
        self::assertSame('stored', $result->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testPublishResolvesRejectedWhenTheRelayDeclinesTheEvent(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"blocked: spam"]', $event->getId()->toHex()));

        $result = $future->await();

        self::assertFalse($result->isAccepted());
        self::assertSame('blocked: spam', $result->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testPublishResolvesDisconnectedWhenTheSendFails(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = $this->connectSendFailing($relayUrl);

        $result = $connection->publishEvent($relayUrl, EventMother::textNote())->await();

        self::assertFalse($result->isAccepted());
        self::assertSame('disconnected', $result->getMessage());
        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

        $connection->disconnect($relayUrl);
    }

    public function testAConnectionErrorOnAnAlreadyFailedConnectionIsHandledIdempotently(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = $this->connectSendFailing($relayUrl);

        $connection->publishEvent($relayUrl, EventMother::textNote())->await();
        $connection->subscribe(new SubscriptionRequest($relayUrl, new FilterCollection([Filter::from()]), SubscriptionId::generate()));

        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

        $connection->disconnect($relayUrl);
    }

    public function testPublishOnAnAlreadyFailedConnectionResolvesDisconnectedInsteadOfHanging(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = $this->connectSendFailing($relayUrl);
        $connection->publishEvent($relayUrl, EventMother::textNote())->await();

        $result = $connection->publishEvent($relayUrl, EventMother::textNote())->await();

        self::assertSame('disconnected', $result->getMessage());
    }

    public function testPublishToARelayNeverConnectedResolvesDisconnected(): void
    {
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector(new ScriptedWebsocketConnection())));

        $result = $connection->publishEvent($this->relayUrl(), EventMother::textNote())->await();

        self::assertFalse($result->isAccepted());
        self::assertSame('disconnected', $result->getMessage());
    }

    public function testAPublishInFlightWhenTheRelayDropsResolvesDisconnected(): void
    {
        $relayUrl = $this->relayUrl();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $future = $connection->publishEvent($relayUrl, EventMother::textNote());
        delay(0.01);
        $ws->endStream();

        self::assertSame('disconnected', $future->await()->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testAPublishInFlightWhenTheClientDisconnectsResolvesDisconnected(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = $this->connect(new ScriptedWebsocketConnection(), $relayUrl);

        $future = $connection->publishEvent($relayUrl, EventMother::textNote());
        delay(0.01);
        $connection->disconnect($relayUrl);

        self::assertSame('disconnected', $future->await()->getMessage());
    }

    public function testAPublishTheRelayNeverAnswersResolvesTimeout(): void
    {
        $relayUrl = $this->relayUrl();
        $connection = $this->connect(new ScriptedWebsocketConnection(), $relayUrl, publishTimeoutMs: 30);

        $result = $connection->publishEvent($relayUrl, EventMother::textNote())->await();

        self::assertFalse($result->isAccepted());
        self::assertSame('timeout', $result->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testARelaysAnswerBeforeThePublishTimeoutIsTheOutcome(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, publishTimeoutMs: 30);

        $future = $connection->publishEvent($relayUrl, $event);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));
        delay(0.06);

        self::assertTrue($future->await()->isAccepted());

        $connection->disconnect($relayUrl);
    }

    public function testASecondPublishOfAnEventInFlightJoinsTheFirstAndBothSettleOnTheRelaysAnswer(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $first = $connection->publishEvent($relayUrl, $event);
        $second = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,"stored"]', $event->getId()->toHex()));
        delay(0.01);

        self::assertSame([true, true], [$first->isComplete(), $second->isComplete()], 'the first publish must not be stranded by a second publish of the same event');
        self::assertSame($first->await(), $second->await(), 'both callers receive the one outcome');

        $connection->disconnect($relayUrl);
    }

    public function testASecondPublishOfAnEventInFlightIsNotSentAgain(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl);

        $connection->publishEvent($relayUrl, $event);
        $connection->publishEvent($relayUrl, $event);
        delay(0.01);

        self::assertCount(1, array_filter($ws->sentTexts, static fn (string $frame): bool => str_starts_with($frame, '["EVENT"')));

        $connection->disconnect($relayUrl);
    }

    private function connect(ScriptedWebsocketConnection $ws, RelayUrl $relayUrl, int $publishTimeoutMs = 8000): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
        );

        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false, publishTimeoutMs: $publishTimeoutMs));
        delay(0.01);

        return $connection;
    }

    private function connectSendFailing(RelayUrl $relayUrl): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector(new SendFailingWebsocketConnection())),
        );
        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));
        delay(0.01);

        return $connection;
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }
}
