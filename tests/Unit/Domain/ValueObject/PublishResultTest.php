<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\Enum\RelayUnavailability;
use Innis\Nostr\Client\Domain\ValueObject\PublishResult;
use Innis\Nostr\Core\Domain\Enum\ReasonPrefix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishResultTest extends TestCase
{
    public function testAnAcceptedResultCarriesTheRelayMessage(): void
    {
        $result = PublishResult::accepted('stored');

        self::assertTrue($result->isAccepted());
        self::assertSame('stored', $result->getMessage());
    }

    public function testARejectedResultCarriesTheRelayReason(): void
    {
        $result = PublishResult::rejected('blocked: spam');

        self::assertFalse($result->isAccepted());
        self::assertSame('blocked: spam', $result->getMessage());
    }

    #[DataProvider('unavailabilities')]
    public function testAnUnavailableRelayIsNotAccepted(RelayUnavailability $unavailability): void
    {
        self::assertFalse(PublishResult::unavailable($unavailability)->isAccepted());
    }

    #[DataProvider('unavailabilities')]
    public function testAnUnavailableRelayCarriesTheClientsOwnMessage(RelayUnavailability $unavailability): void
    {
        self::assertSame($unavailability->value, PublishResult::unavailable($unavailability)->getMessage());
    }

    #[DataProvider('unavailabilities')]
    public function testAnUnavailableRelaysMessageCannotBeReadAsARelayReason(RelayUnavailability $unavailability): void
    {
        self::assertNull(ReasonPrefix::tryFromMessage(PublishResult::unavailable($unavailability)->getMessage()));
    }

    /**
     * @return array<string, array{RelayUnavailability}>
     */
    public static function unavailabilities(): array
    {
        return [
            'failed to connect' => [RelayUnavailability::FailedToConnect],
            'disconnected' => [RelayUnavailability::Disconnected],
            'timeout' => [RelayUnavailability::Timeout],
        ];
    }
}
