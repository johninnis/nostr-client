<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Enum;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;

enum ReservedSubscriptionId: string
{
    case KeepAlive = 'keepalive';

    public function toSubscriptionId(): SubscriptionId
    {
        return SubscriptionId::tryFromString($this->value)
            ?? throw new InvalidArgumentException(sprintf('Reserved subscription id "%s" is not a valid subscription id', $this->value));
    }
}
