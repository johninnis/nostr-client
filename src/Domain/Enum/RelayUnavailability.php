<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Enum;

// Deliberate: the client's own words carry no reason prefix, so they are never read as a relay's reason - see ADR-0015
enum RelayUnavailability: string
{
    case FailedToConnect = 'failed to connect';
    case Disconnected = 'disconnected';
    case Timeout = 'timeout';
}
