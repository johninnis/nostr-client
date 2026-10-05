<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Infrastructure\Connection;

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\ChallengeSigningAuthHandler;
use Innis\Nostr\Client\Tests\Support\EventMother;
use Innis\Nostr\Client\Tests\Support\FakeWebsocketConnector;
use Innis\Nostr\Client\Tests\Support\FixedAuthChallengeHandler;
use Innis\Nostr\Client\Tests\Support\RecordingAuthResultListener;
use Innis\Nostr\Client\Tests\Support\RecordingEventHandler;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Challenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage as RelayEventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class AmphpRelayConnectionAuthRetryTest extends TestCase
{
    private const string CHALLENGE = 'challenge-xyz';

    public function testAuthRequiredPublishIsParkedAndRetransmittedAfterAuthAccepted(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

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
        $event = EventMother::textNote();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

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
        self::assertSame('auth-required: auth rejected: error: authentication failed', $result->getMessage());
        self::assertSame(ReasonPrefix::AuthRequired, ReasonPrefix::tryFromMessage($result->getMessage()), 'the synthetic reason carries a machine-readable prefix followed by a colon');
        self::assertSame(1, self::countFrames($ws, 'EVENT'), 'a rejected auth must fail the parked publish, never retransmit it');

        $connection->disconnect($relayUrl);
    }

    public function testAwaitPendingPublishesBlocksUntilAnAuthParkedPublishResolves(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

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

    public function testAuthRequiredPublishIsRejectedWhenNoAuthHandlerRegistered(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
        );
        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false));
        delay(0.01);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);

        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));

        $result = $future->await();
        self::assertFalse($result->isAccepted(), 'without an auth handler an auth-required publish resolves as rejected, never hangs');
        self::assertStringContainsString('auth-required', $result->getMessage());
        self::assertSame(1, self::countFrames($ws, 'EVENT'), 'a publish that cannot be authenticated is never retransmitted');

        $connection->disconnect($relayUrl);
    }

    public function testAuthAcceptedNotifiesTheAuthResultListener(): void
    {
        $relayUrl = $this->relayUrl();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);
        $recorder = new RecordingAuthResultListener();
        $connection->setAuthResultListener($recorder);

        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);

        self::assertSame([['relay' => (string) $relayUrl, 'accepted' => true, 'message' => '']], $recorder->results);

        $connection->disconnect($relayUrl);
    }

    public function testAuthRejectedNotifiesTheAuthResultListenerWithTheReason(): void
    {
        $relayUrl = $this->relayUrl();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);
        $recorder = new RecordingAuthResultListener();
        $connection->setAuthResultListener($recorder);

        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"error: authentication failed"]', $authEvent->getId()->toHex()));
        delay(0.01);

        self::assertCount(1, $recorder->results);
        self::assertFalse($recorder->results[0]['accepted']);
        self::assertStringContainsString('authentication failed', $recorder->results[0]['message']);

        $connection->disconnect($relayUrl);
    }

    public function testAnAuthRequiredAnswerToTheAuthEventAnswersTheFreshChallengeInsteadOfFailingTheParkedPublish(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $handler = new ChallengeSigningAuthHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, $handler);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        $ws->pushInbound('["AUTH","first"]');
        delay(0.01);
        $firstAuth = $handler->answers['first'];

        $ws->pushInbound('["AUTH","fresh"]');
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: challenge issued, please retry"]', $firstAuth->getId()->toHex()));
        delay(0.01);

        self::assertFalse($future->isComplete(), 'an auth-required answer to our AUTH asks for the fresh challenge; it is not a final refusal');
        self::assertSame(['first', 'fresh'], $handler->challenges, 'the fresh challenge is answered');
        self::assertSame(2, self::countFrames($ws, 'AUTH'));

        $freshAuth = $handler->answers['fresh'];
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $freshAuth->getId()->toHex()));
        delay(0.01);
        self::assertSame(2, self::countFrames($ws, 'EVENT'), 'the parked publish is resent once the fresh answer is accepted');

        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));
        self::assertTrue($future->await()->isAccepted());

        $connection->disconnect($relayUrl);
    }

    public function testAFreshChallengeArrivingAfterTheAuthRequiredAnswerIsAnsweredOnArrival(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $handler = new ChallengeSigningAuthHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, $handler);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound('["AUTH","first"]');
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.01);
        $firstAuth = $handler->answers['first'];

        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: challenge issued, please retry"]', $firstAuth->getId()->toHex()));
        delay(0.01);
        self::assertFalse($future->isComplete());
        self::assertSame(['first'], $handler->challenges, 'an already answered challenge is not answered twice');

        $ws->pushInbound('["AUTH","fresh"]');
        delay(0.01);
        self::assertSame(['first', 'fresh'], $handler->challenges);

        $freshAuth = $handler->answers['fresh'];
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $freshAuth->getId()->toHex()));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));
        self::assertTrue($future->await()->isAccepted());

        $connection->disconnect($relayUrl);
    }

    public function testADeclinedChallengeSettlesTheParkedPublish(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new FixedAuthChallengeHandler(null));

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);

        self::assertTrue($future->isComplete(), 'a declined challenge must not leave the publish parked forever');
        $result = $future->await();
        self::assertFalse($result->isAccepted());
        self::assertSame('auth-required: auth declined', $result->getMessage());
        self::assertSame(0, self::countFrames($ws, 'AUTH'));
        self::assertSame(1, self::countFrames($ws, 'EVENT'));

        $connection->disconnect($relayUrl);
    }

    public function testAnAuthRequiredRefusalAfterADeclinedChallengeIsReturnedAtOnce(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new FixedAuthChallengeHandler(null));

        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));

        self::assertSame('auth-required: please authenticate', $future->await()->getMessage(), 'a connection whose challenge was declined cannot authenticate, so the relay\'s refusal is the outcome');

        $connection->disconnect($relayUrl);
    }

    public function testAParkedPublishSettlesWhenTheRelayNeverAnswersTheAuthEvent(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new ChallengeSigningAuthHandler(), authTimeoutMs: 50);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        self::assertFalse($future->isComplete());

        delay(0.1);

        self::assertTrue($future->isComplete(), 'the auth timeout bounds how long work stays parked');
        self::assertSame('auth-required: auth timed out', $future->await()->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testAParkedPublishSettlesWhenTheRelayNeverSendsAChallenge(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new ChallengeSigningAuthHandler(), authTimeoutMs: 50);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.1);

        self::assertSame('auth-required: auth timed out', $future->await()->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testAChallengeReceivedBeforeTheHandlerWasSetIsAnsweredWhenAPublishIsRefused(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $handler = new ChallengeSigningAuthHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, null);
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $connection->setAuthHandler($handler);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.01);

        self::assertSame([self::CHALLENGE], $handler->challenges, 'the stored challenge is answered when the refusal arrives');

        $authEvent = $handler->answers[self::CHALLENGE];
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $event->getId()->toHex()));
        self::assertTrue($future->await()->isAccepted());

        $connection->disconnect($relayUrl);
    }

    public function testAnAuthRequiredRefusalAfterTheRelayAcceptedAuthIsTheOutcome(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: still not allowed"]', $event->getId()->toHex()));

        self::assertSame('auth-required: still not allowed', $future->await()->getMessage(), 'a relay that refuses after accepting AUTH ends the work instead of looping');
        self::assertSame(1, self::countFrames($ws, 'EVENT'));

        $connection->disconnect($relayUrl);
    }

    public function testAnAuthRequiredClosedSubscriptionIsReissuedOnTheSameIdOnceTheRelayAcceptsAuth(): void
    {
        $relayUrl = $this->relayUrl();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));
        $subscriptionId = $this->subscriptionId('sub-dm');
        $events = new RecordingEventHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);

        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), $events);
        delay(0.01);
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: we can\'t serve DMs to unauthenticated users"]');
        delay(0.01);

        self::assertSame([], $events->closedReasons, 'an auth-required CLOSED parks the subscription instead of ending it');
        self::assertSame(1, self::countFrames($ws, 'REQ'));

        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);

        $reqFrames = array_values(array_filter($ws->sentTexts, static fn (string $frame): bool => str_starts_with($frame, '["REQ"')));
        self::assertCount(2, $reqFrames, 'the REQ is re-issued once the relay accepts AUTH');
        self::assertSame($reqFrames[0], $reqFrames[1], 'the REQ is re-issued on the same id with the same filters');

        $message = EventMother::textNote('dm');
        $ws->pushInbound(new RelayEventMessage($subscriptionId, $message)->toJson());
        delay(0.01);
        self::assertSame([$message->getId()->toHex()], $events->eventIds);

        $connection->disconnect($relayUrl);
    }

    public function testAParkedSubscriptionIsClosedWhenTheRelayRejectsAuth(): void
    {
        $relayUrl = $this->relayUrl();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));
        $subscriptionId = $this->subscriptionId('sub-dm');
        $events = new RecordingEventHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);

        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), $events);
        delay(0.01);
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: authenticate first"]');
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"restricted: not on the list"]', $authEvent->getId()->toHex()));
        delay(0.01);

        self::assertSame(['auth-required: auth rejected: restricted: not on the list'], $events->closedReasons);
        self::assertSame(1, self::countFrames($ws, 'REQ'));
        self::assertFalse($connection->getConnection($relayUrl)?->hasSubscription($subscriptionId));

        $connection->disconnect($relayUrl);
    }

    public function testAParkedSubscriptionIsClosedWhenTheChallengeIsDeclined(): void
    {
        $relayUrl = $this->relayUrl();
        $subscriptionId = $this->subscriptionId('sub-dm');
        $events = new RecordingEventHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new FixedAuthChallengeHandler(null));

        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), $events);
        delay(0.01);
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: authenticate first"]');
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);

        self::assertSame(['auth-required: auth declined'], $events->closedReasons);

        $connection->disconnect($relayUrl);
    }

    public function testAnAuthRequiredClosedIsTerminalWhenNoAuthHandlerRegistered(): void
    {
        $relayUrl = $this->relayUrl();
        $subscriptionId = $this->subscriptionId('sub-dm');
        $events = new RecordingEventHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, null);

        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), $events);
        delay(0.01);
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: authenticate first"]');
        delay(0.01);

        self::assertSame(['auth-required: authenticate first'], $events->closedReasons);

        $connection->disconnect($relayUrl);
    }

    public function testAnUnsubscribedParkedSubscriptionIsNotReissued(): void
    {
        $relayUrl = $this->relayUrl();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));
        $subscriptionId = $this->subscriptionId('sub-dm');

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connect($ws, $relayUrl, $authEvent);

        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), new RecordingEventHandler());
        delay(0.01);
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: authenticate first"]');
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.01);
        $connection->unsubscribe($relayUrl, $subscriptionId);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);

        self::assertSame(1, self::countFrames($ws, 'REQ'));

        $connection->disconnect($relayUrl);
    }

    public function testAConnectionDropSettlesAParkedPublishAndClosesAParkedSubscriptionOnce(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $subscriptionId = $this->subscriptionId('sub-dm');
        $events = new RecordingEventHandler();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new ChallengeSigningAuthHandler(), authTimeoutMs: 50);

        $future = $connection->publishEvent($relayUrl, $event);
        $connection->subscribe(SubscriptionRequest::for($relayUrl, $this->kindFourFilter(), $subscriptionId), $events);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        $ws->pushInbound('["CLOSED","sub-dm","auth-required: authenticate first"]');
        delay(0.01);

        $ws->endStream();
        delay(0.1);

        self::assertSame(['disconnected'], $events->closedReasons, 'a dropped connection closes the parked subscription once, and its auth timeout never fires');
        $result = $future->await();
        self::assertFalse($result->isAccepted());
        self::assertSame('disconnected', $result->getMessage(), 'a dropped connection settles the parked publish as that relay\'s outcome');
    }

    public function testAParkedPublishIsBoundedByTheAuthTimeoutNotThePublishTimeout(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new ChallengeSigningAuthHandler(), authTimeoutMs: 150, publishTimeoutMs: 40);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        delay(0.08);

        self::assertFalse($future->isComplete(), 'the publish timeout is suspended while the publish is parked on auth');

        self::assertSame('auth-required: auth timed out', $future->await()->getMessage());

        $connection->disconnect($relayUrl);
    }

    public function testTheResentEventRestartsThePublishTimeout(): void
    {
        $relayUrl = $this->relayUrl();
        $event = EventMother::textNote();
        $authEvent = EventMother::auth(new RelayChallenge($relayUrl, Challenge::fromString(self::CHALLENGE)));

        $ws = new ScriptedWebsocketConnection();
        $connection = $this->connectWith($ws, $relayUrl, new FixedAuthChallengeHandler($authEvent), publishTimeoutMs: 40);

        $future = $connection->publishEvent($relayUrl, $event);
        delay(0.01);
        $ws->pushInbound(sprintf('["OK","%s",false,"auth-required: please authenticate"]', $event->getId()->toHex()));
        $ws->pushInbound(sprintf('["AUTH","%s"]', self::CHALLENGE));
        delay(0.06);
        $ws->pushInbound(sprintf('["OK","%s",true,""]', $authEvent->getId()->toHex()));
        delay(0.01);

        self::assertSame(2, self::countFrames($ws, 'EVENT'));
        self::assertFalse($future->isComplete(), 'the resend starts a fresh publish timeout');

        self::assertSame('timeout', $future->await()->getMessage(), 'a resent event the relay never answers settles as timeout');

        $connection->disconnect($relayUrl);
    }

    private function connect(ScriptedWebsocketConnection $ws, RelayUrl $relayUrl, Event $authEvent): AmphpRelayConnection
    {
        return $this->connectWith($ws, $relayUrl, new FixedAuthChallengeHandler($authEvent));
    }

    private function connectWith(ScriptedWebsocketConnection $ws, RelayUrl $relayUrl, ?AuthChallengeHandlerInterface $handler, int $authTimeoutMs = 60000, int $publishTimeoutMs = 8000): AmphpRelayConnection
    {
        $connection = new AmphpRelayConnection(
            new ConnectionFactory(new FakeWebsocketConnector($ws)),
        );
        $connection->setAuthHandler($handler);

        $connection->connect($relayUrl, new ConnectionConfig(autoReconnect: false, authTimeoutMs: $authTimeoutMs, publishTimeoutMs: $publishTimeoutMs));
        delay(0.01);

        return $connection;
    }

    private function subscriptionId(string $value): SubscriptionId
    {
        $subscriptionId = SubscriptionId::tryFromString($value);
        self::assertNotNull($subscriptionId);

        return $subscriptionId;
    }

    private function kindFourFilter(): Filter
    {
        $filter = Filter::tryFromArray(['kinds' => [4]]);
        self::assertNotNull($filter);

        return $filter;
    }

    private function relayUrl(): RelayUrl
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.test');
        self::assertNotNull($relayUrl);

        return $relayUrl;
    }

    private static function countFrames(ScriptedWebsocketConnection $ws, string $type): int
    {
        return count(array_filter(
            $ws->sentTexts,
            static fn (string $frame): bool => str_starts_with($frame, '["'.$type.'"'),
        ));
    }
}
