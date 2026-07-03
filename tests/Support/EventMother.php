<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use RuntimeException;

final class EventMother
{
    public static function textNote(string $content = 'hello nostr'): Event
    {
        return self::fromRumour(RumourFactory::createTextNote(KeyMother::alicePublicKey(), $content));
    }

    public static function auth(RelayUrl $relayUrl, string $challenge): Event
    {
        return self::fromRumour(RumourFactory::createAuth(KeyMother::alicePublicKey(), $relayUrl, $challenge));
    }

    public static function fromRumour(Rumour $rumour): Event
    {
        return new Event($rumour, $rumour->getId(), self::signature());
    }

    public static function signature(): Signature
    {
        return Signature::tryFromHex(str_repeat('a', 128)) ?? throw new RuntimeException('Invalid fixture signature');
    }
}
