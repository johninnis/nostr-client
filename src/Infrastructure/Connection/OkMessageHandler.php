<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredFuture;
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\Enum\OkOutcome;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use Throwable;

final class OkMessageHandler
{
    private ?AuthChallengeHandlerInterface $authHandler = null;

    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    public function handle(OkMessage $message, RelaySession $session): void
    {
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

        $result = match (OkOutcome::classify($message)) {
            OkOutcome::AuthRequired => null,
            OkOutcome::Accepted => PublishResult::accepted($message->getMessage()),
            OkOutcome::Rejected => PublishResult::rejected($message->getMessage()),
        };

        if (null === $result) {
            $this->parkOrReject($message, $session, $future);

            return;
        }

        $session->removePendingEvent($eventIdHex);
        $future->complete($result);
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

                if (!$parked->getDeferred()->isComplete()) {
                    $parked->getDeferred()->error(
                        ConnectionException::forRelay($session->getConnection()->getRelayUrl(), 'Auth retry failed: '.$e->getMessage())
                    );
                }
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
