<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class InboundMessageDispatcher
{
    public function __construct(
        private LoggerInterface $logger,
        private InboundMessageHandlers $handlers,
    ) {
    }

    public function dispatch(RelaySession $session, string $rawMessage): void
    {
        $relayUrl = $session->getConnection()->getRelayUrl();

        try {
            $message = RelayMessage::tryFromJson($rawMessage);

            if (null === $message) {
                $this->logger->warning('Unknown or malformed relay message', [
                    'relay' => (string) $relayUrl,
                ]);

                return;
            }

            $handler = $this->handlers->handlerFor($message);

            if (null === $handler) {
                $this->logger->warning('Unhandled relay message type', [
                    'relay' => (string) $relayUrl,
                    'message_type' => $message->type()->value,
                ]);

                return;
            }

            $handler->handle($message, $session);
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
