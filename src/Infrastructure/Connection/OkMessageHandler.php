<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredFuture;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\Enum\OkOutcome;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use LogicException;
use Override;
use Throwable;

final class OkMessageHandler implements RelayMessageHandlerInterface
{
    private ?AuthChallengeHandlerInterface $authHandler = null;

    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    #[Override]
    public function handles(): RelayMessageType
    {
        return RelayMessageType::Ok;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly.
        if (!$message instanceof OkMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        $eventIdHex = $message->getEventId()->toHex();

        if ($session->isPendingAuth($eventIdHex)) {
            $this->acknowledgeAuth($message, $session);

            return;
        }

        $future = $session->getPendingResponse($eventIdHex);

        if (null === $future) {
            return;
        }

        $session->removePendingResponse($eventIdHex);

        match (OkOutcome::classify($message)) {
            OkOutcome::AuthRequired => $this->parkOrReject($message, $session, $future),
            OkOutcome::Accepted, OkOutcome::Rejected => $this->settlePublish($message, $session, $future),
        };
    }

    private function acknowledgeAuth(OkMessage $message, RelaySession $session): void
    {
        $session->clearPendingAuth($message->getEventId()->toHex());

        if ($message->isAccepted()) {
            $this->flushAuthRetryQueue($session);
        } else {
            $this->failAuthRetryQueue($session, $message->getMessage());
        }
    }

    /**
     * @param DeferredFuture<PublishResult> $future
     */
    // Deliberate: with no handler the challenge can never be signed, so return the relay's rejection rather than park it forever - see ADR-0004
    private function parkOrReject(OkMessage $message, RelaySession $session, DeferredFuture $future): void
    {
        $eventIdHex = $message->getEventId()->toHex();

        if (null === $this->authHandler) {
            $session->removePendingEvent($eventIdHex);
            $future->complete(PublishResult::rejected($message->getMessage()));

            return;
        }

        $session->parkPublish(new ParkedPublish($eventIdHex, $future));
    }

    /**
     * @param DeferredFuture<PublishResult> $future
     */
    private function settlePublish(OkMessage $message, RelaySession $session, DeferredFuture $future): void
    {
        $session->removePendingEvent($message->getEventId()->toHex());
        $future->complete($message->isAccepted()
            ? PublishResult::accepted($message->getMessage())
            : PublishResult::rejected($message->getMessage()));
    }

    private function flushAuthRetryQueue(RelaySession $session): void
    {
        foreach ($session->takeAuthRetryQueue() as $parked) {
            $eventIdHex = $parked->getEventIdHex();
            $event = $session->getPendingEvent($eventIdHex);

            if (null === $event) {
                $parked->getDeferred()->error(
                    ConnectionException::forRelay($session->getConnection()->getRelayUrl(), 'Auth retry failed: event no longer available')
                );
                continue;
            }

            $session->setPendingResponse($eventIdHex, $parked->getDeferred());

            try {
                $session->send(new EventMessage($event));
            } catch (Throwable $e) {
                $session->removePendingResponse($eventIdHex);
                $session->removePendingEvent($eventIdHex);
                $parked->getDeferred()->error(
                    ConnectionException::forRelay($session->getConnection()->getRelayUrl(), 'Auth retry failed: '.$e->getMessage())
                );
            }
        }
    }

    private function failAuthRetryQueue(RelaySession $session, string $reason): void
    {
        foreach ($session->takeAuthRetryQueue() as $parked) {
            $session->removePendingEvent($parked->getEventIdHex());
            $parked->getDeferred()->complete(PublishResult::rejected('auth-required, auth rejected: '.$reason));
        }
    }
}
