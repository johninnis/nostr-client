<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;

interface InboundMessageHandlerInterface
{
    /**
     * @return class-string<RelayMessage>
     */
    public function handledMessageType(): string;

    public function handle(RelayMessage $message, RelaySession $session): void;
}
