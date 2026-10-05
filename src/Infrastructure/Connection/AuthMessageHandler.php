<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\AuthResultListenerInterface;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage as ClientAuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;
use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\weakClosure;

final class AuthMessageHandler implements InboundMessageHandlerInterface
{
    private ?AuthChallengeHandlerInterface $authHandler = null;
    private ?AuthResultListenerInterface $authResultListener = null;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    public function setAuthResultListener(AuthResultListenerInterface $listener): void
    {
        $this->authResultListener = $listener;
    }

    /**
     * @return class-string<RelayMessage>
     */
    #[Override]
    public function handledMessageType(): string
    {
        return AuthMessage::class;
    }

    #[Override]
    public function handle(RelayMessage $message, RelaySession $session): void
    {
        match (true) {
            $message instanceof AuthMessage => $this->handleAuth($message, $session),
            default => throw new InvalidArgumentException('AuthMessageHandler cannot handle '.$message::class),
        };
    }

    private function handleAuth(AuthMessage $message, RelaySession $session): void
    {
        $session->storeChallenge($message->getChallenge());

        if (null === $this->authHandler) {
            $this->logger->debug('AUTH challenge stored but no handler configured', [
                'relay' => (string) $session->getConnection()->getRelayUrl(),
            ]);

            return;
        }

        $this->answerStoredChallenge($session);
    }

    // Deliberate: auth-required work is parked only while a handler can still authenticate this connection - see ADR-0014
    public function park(RelaySession $session, ParkedWorkInterface $work): bool
    {
        if (null === $this->authHandler || $session->isAuthenticated() || $session->isAuthDeclined()) {
            return false;
        }

        $session->park($work);
        $this->answerStoredChallenge($session);
        $this->refreshAuthTimeout($session);

        return true;
    }

    // Deliberate: an auth-required answer to our AUTH asks for the fresh challenge, not a final refusal - see ADR-0014
    public function acknowledge(OkMessage $message, RelaySession $session): void
    {
        $session->endAuthAttempt();
        $session->cancelAuthTimeout();

        if ($message->isAccepted()) {
            $session->markAuthenticated();
            $this->resumeParked($session);
        } elseif ($message->isAuthRequired()) {
            $this->answerStoredChallenge($session);
        } else {
            $this->refuseParked($session, ReasonPrefix::AuthRequired->format('auth rejected: '.$message->getMessage()));
        }

        $this->refreshAuthTimeout($session);

        $this->authResultListener?->onAuthResult(
            $session->getConnection()->getRelayUrl(),
            $message->isAccepted(),
            $message->getMessage(),
        );
    }

    private function answerStoredChallenge(RelaySession $session): void
    {
        $challenge = $session->getChallenge();

        if (null === $this->authHandler || null === $challenge || !$session->hasUnansweredChallenge()) {
            return;
        }

        if ($session->isAuthenticated() || $session->hasAuthAttempt()) {
            return;
        }

        $relayUrl = $session->getConnection()->getRelayUrl();
        $session->markChallengeAnswered();

        try {
            $authEvent = $this->authHandler->handleAuthChallenge(new RelayChallenge($relayUrl, $challenge));
        } catch (Throwable $e) {
            $this->logger->error('AUTH challenge handler failed', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (null === $authEvent) {
            $session->declineAuth();
            $this->refuseParked($session, ReasonPrefix::AuthRequired->format('auth declined'));
            $this->refreshAuthTimeout($session);

            return;
        }

        $session->beginAuthAttempt($authEvent->getId()->toHex());

        try {
            $session->send(ClientAuthMessage::fromEvent($authEvent));
        } catch (Throwable $e) {
            $session->endAuthAttempt();
            $this->logger->error('AUTH response could not be sent', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $session->cancelAuthTimeout();
        $this->refreshAuthTimeout($session);

        $this->logger->debug('AUTH response sent', [
            'relay' => (string) $relayUrl,
        ]);
    }

    // Deliberate: one timeout spans the answer in flight and the parked work, so neither can wait on the relay forever - see ADR-0014
    private function refreshAuthTimeout(RelaySession $session): void
    {
        if (!$session->hasAuthAttempt() && !$session->hasParked()) {
            $session->cancelAuthTimeout();

            return;
        }

        if (!$session->hasAuthTimeout()) {
            $session->startAuthTimeout(weakClosure(function () use ($session): void {
                $this->expire($session);
            }));
        }
    }

    private function expire(RelaySession $session): void
    {
        $this->logger->warning('NIP-42 AUTH was not completed in time', [
            'relay' => (string) $session->getConnection()->getRelayUrl(),
        ]);

        $session->endAuthAttempt();
        $this->refuseParked($session, ReasonPrefix::AuthRequired->format('auth timed out'));
    }

    private function resumeParked(RelaySession $session): void
    {
        foreach ($session->takeParked() as $work) {
            $work->resume($session);
        }
    }

    private function refuseParked(RelaySession $session, string $reason): void
    {
        foreach ($session->takeParked() as $work) {
            $work->refuse($session, $reason);
        }
    }
}
