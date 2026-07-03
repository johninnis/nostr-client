<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\AuthMessage as ClientAuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

final class AuthMessageHandler implements RelayMessageHandlerInterface
{
    private ?AuthChallengeHandlerInterface $authHandler = null;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function setAuthHandler(?AuthChallengeHandlerInterface $handler): void
    {
        $this->authHandler = $handler;
    }

    #[Override]
    public function handles(): RelayMessageType
    {
        return RelayMessageType::Auth;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        // The dispatcher only routes a message to the handler registered for its type, so this
        // narrowing never fails; a mismatch is a wiring fault and must fail loudly.
        if (!$message instanceof AuthMessage) {
            throw new LogicException(sprintf('%s cannot handle %s', self::class, $message::class));
        }

        $relayUrl = $session->getConnection()->getRelayUrl();

        if (null === $this->authHandler) {
            $this->logger->debug('AUTH challenge received but no handler configured', [
                'relay' => (string) $relayUrl,
            ]);

            return;
        }

        try {
            $authEvent = $this->authHandler->handleAuthChallenge($relayUrl, $message->getChallenge());

            if (null !== $authEvent) {
                $this->sendAuth($session, $authEvent);

                $this->logger->debug('AUTH response sent', [
                    'relay' => (string) $relayUrl,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('AUTH challenge handler failed', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendAuth(RelaySession $session, Event $authEvent): void
    {
        $eventIdHex = $authEvent->getId()->toHex();
        $session->markPendingAuth($eventIdHex);

        try {
            $session->send(new ClientAuthMessage($authEvent));
        } catch (Throwable $e) {
            $session->clearPendingAuth($eventIdHex);

            throw ConnectionException::forRelay($session->getConnection()->getRelayUrl(), $e->getMessage(), $e);
        }
    }
}
