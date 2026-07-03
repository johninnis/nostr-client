<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\Enum;

use Innis\Nostr\Client\Domain\Enum\OkOutcome;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\OkMessage;
use PHPUnit\Framework\TestCase;

final class OkOutcomeTest extends TestCase
{
    public function testAnAuthRequiredRejectionClassifiesAsAuthRequired(): void
    {
        $message = new OkMessage($this->eventId(), false, 'auth-required: please authenticate');

        self::assertSame(OkOutcome::AuthRequired, OkOutcome::classify($message));
    }

    public function testAnAcceptedFrameClassifiesAsAccepted(): void
    {
        $message = new OkMessage($this->eventId(), true, 'stored');

        self::assertSame(OkOutcome::Accepted, OkOutcome::classify($message));
    }

    public function testAPlainRejectionClassifiesAsRejected(): void
    {
        $message = new OkMessage($this->eventId(), false, 'blocked: spam');

        self::assertSame(OkOutcome::Rejected, OkOutcome::classify($message));
    }

    private function eventId(): EventId
    {
        return EventId::tryFromHex(str_repeat('a', 64)) ?? self::fail('invalid event id');
    }
}
