<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Enum;

use Innis\Nostr\Client\Domain\Enum\ReservedSubscriptionId;
use PHPUnit\Framework\TestCase;

final class ReservedSubscriptionIdTest extends TestCase
{
    public function testTheKeepAliveIdIsTheOneTheHeartbeatAndThePingReplyClose(): void
    {
        self::assertSame('keepalive', (string) ReservedSubscriptionId::KeepAlive->toSubscriptionId());
    }
}
