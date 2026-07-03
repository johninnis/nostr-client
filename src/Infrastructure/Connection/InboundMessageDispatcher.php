<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\AuthMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\ClosedMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EoseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\EventMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class InboundMessageDispatcher
{
    public function __construct(
        private MessageDeserialiserInterface $deserialiser,
        private LoggerInterface $logger,
        private EventMessageHandler $event,
        private OkMessageHandler $ok,
        private EoseMessageHandler $eose,
        private ClosedMessageHandler $closed,
        private NoticeMessageHandler $notice,
        private AuthMessageHandler $auth,
    ) {
    }

    public function dispatch(RelaySession $session, string $rawMessage): void
    {
        $relayUrl = $session->getConnection()->getRelayUrl();

        try {
            $message = $this->deserialiser->deserialiseRelayMessage($rawMessage);

            if (null === $message) {
                $this->logger->warning('Unknown or malformed relay message', [
                    'relay' => (string) $relayUrl,
                ]);

                return;
            }

            match (true) {
                $message instanceof EventMessage => $this->event->handle($message, $session),
                $message instanceof OkMessage => $this->ok->handle($message, $session),
                $message instanceof EoseMessage => $this->eose->handle($message, $session),
                $message instanceof ClosedMessage => $this->closed->handle($message, $session),
                $message instanceof NoticeMessage => $this->notice->handle($message, $session),
                $message instanceof AuthMessage => $this->auth->handle($message, $session),
                default => $this->logger->warning('Unhandled relay message type', [
                    'relay' => (string) $relayUrl,
                    'message_type' => $message->type()->value,
                ]),
            };
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Unknown or malformed relay message', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to handle relay message', [
                'relay' => (string) $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
