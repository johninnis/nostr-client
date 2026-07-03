<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Client\Infrastructure\Connection\RelayMessageHandlerInterface;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Core\Domain\Enum\RelayMessageType;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\RelayMessage;
use Override;

final class RecordingRelayMessageHandler implements RelayMessageHandlerInterface
{
    public ?RelayMessage $received = null;

    public function __construct(private readonly RelayMessageType $type)
    {
    }

    #[Override]
    public function handles(): RelayMessageType
    {
        return $this->type;
    }

    #[Override]
    public function handle(RelaySession $session, RelayMessage $message): void
    {
        $this->received = $message;
    }
}
