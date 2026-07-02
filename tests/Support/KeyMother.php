<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use RuntimeException;

final class KeyMother
{
    public const string ALICE_PUBLIC_KEY_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';

    public static function alicePublicKey(): PublicKey
    {
        return PublicKey::fromHex(self::ALICE_PUBLIC_KEY_HEX) ?? throw new RuntimeException('Invalid test public key');
    }
}
