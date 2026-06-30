<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\FixedAuthChallengeHandler;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\EventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class AmphpRelayConnectionAuthRetryTest extends TestCase
{
    private const string CHALLENGE = 'challenge-xyz';

    public function testAuthRequiredPublishIsParkedAndRetransmittedAfterAuthAccepted(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();
        $authEvent = EventFactory::createAuth($event->getPubkey(), $relayUrl, self::CHALLENGE);

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        self::assertSame(1, self::countFrames($ws, 'EVENT'));

        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.01);
        self::assertSame(1, self::countFrames($ws, 'EVENT'), 'an auth-required publish must be parked, not retransmitted yet');

        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        self::assertSame(1, self::countFrames($ws, 'AUTH'), 'the signed auth event must be sent in response to the challenge');

        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);
        self::assertSame(2, self::countFrames($ws, 'EVENT'), 'the parked publish must be retransmitted once auth is accepted');

        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));
        self::assertTrue($future->await()->isAccepted(), 'the publish resolves accepted once stored after auth');

        $connection->disconnect($relayUrl);
    }

    public function testAuthRejectedDoesNotRetransmitTheParkedPublish(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();
        $authEvent = EventFactory::createAuth($event->getPubkey(), $relayUrl, self::CHALLENGE);

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);

        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.01);
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        self::assertSame(1, self::countFrames($ws, 'EVENT'));

        $ws->pushInbound(sprintf('["OK","%s",false,"error: authentication failed"]', $authEvent->getId()->toHex()));

        $result = $future->await();
        self::assertFalse($result->isAccepted(), 'a rejected auth resolves the parked publish as rejected');
        self::assertStringContainsString('auth', $result->getMessage());
        self::assertSame(1, self::countFrames($ws, 'EVENT'), 'a rejected auth must fail the parked publish, never retransmit it');

        $connection->disconnect($relayUrl);
    }

    public function testAwaitPendingPublishesBlocksUntilAnAuthParkedPublishResolves(): void
    {
        $relayUrl = $this->relayUrl();
        $event = $this->textNote();
        $authEvent = EventFactory::createAuth($event->getPubkey(), $relayUrl, self::CHALLENGE);

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);

        $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.01);

        $awaiting = async(static function () use ($connection, $relayUrl): void {
            $connection->awaitPendingPublishes($relayUrl);
        });

        delay(0.02);
        self::assertFalse($awaiting->isComplete(), 'awaitPendingPublishes must keep waiting while a publish is parked on auth');

        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));

        $awaiting->await();
        self::assertTrue($awaiting->isComplete(), 'awaitPendingPublishes must return once the parked publish is finally accepted');

        $connection->disconnect($relayUrl);
    }

    private function connect(ScriptedWebsocketConnection $ws, RelayUrl $relayUrl, Event $authEvent): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
            new JsonMessageDeserialiser(),
        );
        $connection->setAuthHandler(new FixedAuthChallengeHandler($authEvent));

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

    private static function countFrames(ScriptedWebsocketConnection $ws, string $type): int
    {
        return count(array_filter(
            $ws->sentTexts,
            static fn (string $frame): bool => str_starts_with($frame, '["'.$type.'"'),
        ));
    }
}
