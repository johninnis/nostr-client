<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\Service\MessageDeserialiserInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class InboundMessageDispatcher
{
    /** @var array<value-of<RelayMessageType>, RelayMessageHandlerInterface> */
    private array $handlers;

    public function __construct(
        private MessageDeserialiserInterface $deserialiser,
        private LoggerInterface $logger,
        RelayMessageHandlerInterface ...$handlers,
    ) {
        $indexed = [];
        foreach ($handlers as $handler) {
            $indexed[$handler->handles()->value] = $handler;
        }
        $this->handlers = $indexed;
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

            $handler = $this->handlers[$message->type()->value] ?? null;

            if (null === $handler) {
                $this->logger->warning('Unhandled relay message type', [
                    'relay' => (string) $relayUrl,
                    'message_type' => $message->type()->value,
                ]);

                return;
            }

            $handler->handle($session, $message);
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
