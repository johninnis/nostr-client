<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayChallenge;
use Override;

final class ChallengeSigningAuthHandler implements AuthChallengeHandlerInterface
{
    /** @var list<string> */
    public array $challenges = [];

    /** @var array<string, Event> */
    public array $answers = [];

    #[Override]
    public function handleAuthChallenge(RelayChallenge $relayChallenge): Event
    {
        $challenge = (string) $relayChallenge->getChallenge();
        $this->challenges[] = $challenge;
        $answer = EventMother::auth($relayChallenge);
        $this->answers[$challenge] = $answer;

        return $answer;
    }
}
