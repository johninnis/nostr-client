<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\FixedAuthChallengeHandler;
use Innis\Nostr\Client\Tests\Support\RecordingEventHandler;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\EoseHint;
use Innis\Nostr\Core\Domain\Enum\SubscriptionState;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\ReqMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage as RelayEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Override;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class AmphpRelayConnectionWideningTest extends TestCase
{
    private const string CHALLENGE = 'challenge-xyz';
    private const string SUBSCRIPTION = 'sub-feed';

    public function testANarrowedSubscriptionIsSentAgainOnTheSameIdOnceTheRelayAcceptsAuth(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->acceptAuth($ws, $auth);

        self::assertSame([$this->req(), $this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionIsSentAgainAtOnceWhenTheRelayAcceptedAuthBeforeItsEose(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req(), $this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionIsNotSentAgainBeforeTheRelayAcceptsAuth(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionStaysOpenWhenTheChallengeIsDeclined(): void
    {
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler(null));
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([], $events->closedReasons);
    }

    public function testANarrowedSubscriptionStaysOpenWhenTheAuthTimesOut(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth), authTimeoutMs: 50);
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        delay(0.1);

        self::assertSame([], $events->closedReasons);
    }

    public function testANarrowedSubscriptionIsNotSentAgainWhenTheRelayRejectsAuth(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->pushAndSettle($ws, sprintf('["OK","%s",false,"restricted: not on the list"]', $auth->getId()->toHex()));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionIsSentAgainWhenTheRelayAcceptsAuthAfterRejectingIt(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->pushAndSettle($ws, sprintf('["OK","%s",false,"restricted: not on the list"]', $auth->getId()->toHex()));
        $this->authenticate($ws, $auth);

        self::assertSame([$this->req(), $this->req()], self::reqFrames($ws));
    }

    public function testASubscriptionClosedByItsListenerAtTheEndOfStoredEventsIsNotSentAgain(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->authenticate($ws, $auth);
        $listener = new class($connection, $this->relayUrl()) implements EventHandlerInterface {
            public function __construct(private readonly AmphpRelayConnection $connection, private readonly RelayUrl $relayUrl)
            {
            }

            #[Override]
            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
            }

            #[Override]
            public function handleEose(SubscriptionId $subscriptionId): void
            {
                $this->connection->unsubscribe($this->relayUrl, $subscriptionId);
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
        $this->subscribe($connection, $listener);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testARepeatedEndOfStoredEventsIsNotPassedOn(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);

        $this->pushAndSettle($ws, $this->eose());
        $this->pushAndSettle($ws, $this->eose());

        self::assertSame([self::SUBSCRIPTION], $events->eoseSubscriptionIds);
    }

    public function testTheAuthHintOfARepeatedEndOfStoredEventsIsIgnored(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose());
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionIsSentAgainOnlyOnce(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req(), $this->req()], self::reqFrames($ws));
    }

    public function testAnUnsubscribedNarrowedSubscriptionIsNotSentAgain(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $connection->unsubscribe($this->relayUrl(), $this->subscriptionId());
        $this->acceptAuth($ws, $auth);

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testAnEndOfStoredEventsWithoutTheAuthHintIsNotSentAgain(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Finish));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testANarrowedSubscriptionIsNotSentAgainOnceTheAuthHandlerIsRemoved(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);
        $connection->setAuthHandler(null);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame([$this->req()], self::reqFrames($ws));
    }

    public function testTheListenerReceivesAnEventAgainAfterTheSubscriptionIsSentAgain(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);
        $note = EventMother::textNote('public');
        $eventFrame = new RelayEventMessage($this->subscriptionId(), $note)->toJson();

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $eventFrame);
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->acceptAuth($ws, $auth);
        $this->pushAndSettle($ws, $eventFrame);

        self::assertSame([$note->getId()->toHex(), $note->getId()->toHex()], $events->eventIds);
    }

    public function testTheListenerIsToldTheEndOfStoredEventsOfTheSubscriptionSentAgain(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->pushAndSettle($ws, $this->eose());

        self::assertSame([self::SUBSCRIPTION, self::SUBSCRIPTION], $events->eoseSubscriptionIds);
    }

    public function testASubscriptionSentAgainAwaitsItsOwnEndOfStoredEvents(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());
        $this->authenticate($ws, $auth);

        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertSame(SubscriptionState::Active, $connection->getConnection($this->relayUrl())?->getSubscriptionState($this->subscriptionId()));
    }

    public function testANarrowedSubscriptionTheRelayThenRefusesForAuthIsIssuedAgainOnlyOnce(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $this->subscribe($connection, new RecordingEventHandler());

        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));
        $this->pushAndSettle($ws, '["CLOSED","sub-feed","auth-required: authenticate first"]');
        $this->acceptAuth($ws, $auth);

        self::assertSame([$this->req(), $this->req()], self::reqFrames($ws));
    }

    public function testASubscriptionReplacedOnItsIdIsSentAgainOnceOfItsOwn(): void
    {
        $auth = $this->authEvent();
        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, new FixedAuthChallengeHandler($auth));
        $events = new RecordingEventHandler();
        $this->subscribe($connection, $events);
        $this->authenticate($ws, $auth);
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        $this->subscribe($connection, $events);
        $this->pushAndSettle($ws, $this->eose(EoseHint::Auth));

        self::assertCount(4, self::reqFrames($ws));
    }

    private function connect(ScriptedWebsocketConnection $ws, ?AuthChallengeHandlerInterface $handler, int $authTimeoutMs = 60000): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(new ConnectionFactory(new FakeWebsocketConnector($ws)));
        $connection->setAuthHandler($handler);
        $connection->connect($this->relayUrl(), new ConnectionConfig(autoReconnect: false, authTimeoutMs: $authTimeoutMs));
        delay(0.01);

        return $connection;
    }

    private function subscribe(AmphpRelayConnection $connection, EventHandlerInterface $events): void
    {
        $connection->subscribe(SubscriptionRequest::for($this->relayUrl(), $this->filter(), $this->subscriptionId()), $events);
        delay(0.01);
    }

    private function authenticate(ScriptedWebsocketConnection $ws, Event $auth): void
    {
        $this->pushAndSettle($ws, sprintf('["AUTH","%s"]', self::CHALLENGE));
        $this->acceptAuth($ws, $auth);
    }

    private function acceptAuth(ScriptedWebsocketConnection $ws, Event $auth): void
    {
        $this->pushAndSettle($ws, sprintf('["OK","%s",true,""]', $auth->getId()->toHex()));
    }

    private function pushAndSettle(ScriptedWebsocketConnection $ws, string $frame): void
    {
        $ws->pushInbound($frame);
        delay(0.01);
    }

    private function eose(EoseHint ...$hints): string
    {
        return EoseMessage::hinting($this->subscriptionId(), ...$hints)->toJson();
    }

    private function req(): string
    {
        return ReqMessage::from($this->subscriptionId(), new FilterCollection([$this->filter()]))->toJson();
    }

    private function authEvent(): Event
    {
        return EventMother::auth(new RelayChallenge($this->relayUrl(), Challenge::fromString(self::CHALLENGE)));
    }

    private function subscriptionId(): SubscriptionId
    {
        return SubscriptionId::tryFromString(self::SUBSCRIPTION) ?? self::fail('invalid subscription id');
    }

    private function filter(): Filter
    {
        return Filter::tryFromArray(['kinds' => [4]]) ?? self::fail('invalid filter');
    }

    private function relayUrl(): RelayUrl
    {
        return RelayUrl::tryFromString('wss://relay.test') ?? self::fail('invalid relay URL');
    }

    /**
     * @return list<string>
     */
    private static function reqFrames(ScriptedWebsocketConnection $ws): array
    {
        return array_values(array_filter($ws->sentTexts, static fn (string $frame): bool => str_starts_with($frame, '["REQ"')));
    }
}
