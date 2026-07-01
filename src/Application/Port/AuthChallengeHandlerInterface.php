<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

interface AuthChallengeHandlerInterface
{
    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event;
}
