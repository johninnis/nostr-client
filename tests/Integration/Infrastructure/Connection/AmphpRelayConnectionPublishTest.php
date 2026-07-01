<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\SendFailingWebsocketConnection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\EventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class AmphpRelayConnectionPublishTest extends TestCase
{
    public function testPublishResolvesAcceptedWhenTheRelayStoresTheEvent(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();

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
        $event = $this->textNote();

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

    public function testPublishErrorsTheFutureWithAConnectionExceptionWhenTheSendFails(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();

        $ws = new SendFailingWebsocketConnection();
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
            new JsonMessageDeserialiser(),
        );
        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));
        delay(0.01);

        $future = $connection->publishEvent($relayUrl, $event);

        $error = null;
        try {
            $future->await();
        } catch (ConnectionException $e) {
            $error = $e;
        }

        self::assertInstanceOf(ConnectionException::class, $error);
        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

        $connection->disconnect($relayUrl);
    }

    public function testAConnectionErrorOnAnAlreadyFailedConnectionIsHandledIdempotently(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();

        $ws = new SendFailingWebsocketConnection();
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
            new JsonMessageDeserialiser(),
        );
        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));
        delay(0.01);

        try {
            $connection->publishEvent($relayUrl, $event)->await();
        } catch (ConnectionException) {
        }

        $connection->subscribeMultiple($relayUrl, SubscriptionId::generate(), new FilterCollection([new Filter()]));

        self::assertSame(ConnectionState::FAILED, $connection->getConnection($relayUrl)?->getState());

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

    private function textNote(): Event
    {
        $pubkey = PublicKey::fromHex(str_repeat('a', 64));
        self::assertNotNull($pubkey);

        return EventFactory::createTextNote($pubkey, 'hello nostr');
    }
}
