<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;

interface RelayMessageHandlerInterface
{
    public function handles(): RelayMessageType;

    public function handle(RelaySession $session, RelayMessage $message): void;
}
