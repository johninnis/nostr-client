<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Override;

final readonly class FixedAuthChallengeHandler implements AuthChallengeHandlerInterface
{
    public function __construct(private ?Event $response)
    {
    }

    #[Override]
    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
    {
        return $this->response;
    }
}
