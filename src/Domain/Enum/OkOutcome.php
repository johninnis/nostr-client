<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Enum;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;

enum OkOutcome
{
    case AuthRequired;
    case Accepted;
    case Rejected;

    public static function classify(OkMessage $message): self
    {
        return match (true) {
            $message->isAuthRequired() => self::AuthRequired,
            $message->isAccepted() => self::Accepted,
            default => self::Rejected,
        };
    }
}
