<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;

interface AuthChallengeHandlerInterface
{
    public function handleAuthChallenge(RelayChallenge $relayChallenge): ?Event;
}
