<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Enum;

use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelayUnavailabilityTest extends TestCase
{
    #[DataProvider('relayPoolMessages')]
    public function testEachOfTheRelayPoolsMessagesNamesAnUnavailability(string $message): void
    {
        self::assertNotNull(RelayUnavailability::tryFrom($message));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function relayPoolMessages(): array
    {
        return [
            'failed to connect' => ['failed to connect'],
            'disconnected' => ['disconnected'],
            'timeout' => ['timeout'],
        ];
    }
}
