<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\ConnectResult;
use PHPUnit\Framework\TestCase;

final class ConnectResultTest extends TestCase
{
    public function testAConnectedResultIsConnected(): void
    {
        self::assertTrue(ConnectResult::connected()->isConnected());
    }

    public function testAConnectedResultHasNoMessage(): void
    {
        self::assertSame('', ConnectResult::connected()->getMessage());
    }

    public function testAFailedResultIsNotConnected(): void
    {
        self::assertFalse(ConnectResult::failedToConnect()->isConnected());
    }

    public function testAFailedResultSaysItFailedToConnect(): void
    {
        self::assertSame('failed to connect', ConnectResult::failedToConnect()->getMessage());
    }
}
