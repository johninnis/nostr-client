<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;

final readonly class InboundMessageHandlers
{
    /**
     * @var array<class-string<RelayMessage>, InboundMessageHandlerInterface>
     */
    private array $handlers;

    public function __construct(InboundMessageHandlerInterface ...$handlers)
    {
        $map = [];
        foreach ($handlers as $handler) {
            $map[$handler->handledMessageType()] = $handler;
        }

        $this->handlers = $map;
    }

    public function handlerFor(RelayMessage $message): ?InboundMessageHandlerInterface
    {
        return $this->handlers[$message::class] ?? null;
    }
}
