<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

interface AuthChallengeHandlerInterface
{
    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event;
}
