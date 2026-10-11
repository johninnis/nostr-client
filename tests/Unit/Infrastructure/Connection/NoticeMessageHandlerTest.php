<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Infrastructure\Connection;

use Innis\Nostr\Client\Domain\Entity\RelayConnection;
use Innis\Nostr\Client\Domain\Enum\ConnectionState;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\NoticeMessageHandler;
use Innis\Nostr\Client\Infrastructure\Connection\RelaySession;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NoticeMessageHandlerTest extends TestCase
{
    public function testAPingNoticeIsAnsweredWithAKeepAliveClose(): void
    {
        $ws = new ScriptedWebsocketConnection();

        new NoticeMessageHandler(new NullLogger())->handle(NoticeMessage::fromString('PiNg'), $this->session($ws));

        self::assertSame(['["CLOSE","keepalive"]'], $ws->sentTexts);
    }

    public function testAPingNoticeWrappedInAsciiWhitespaceAndNulIsAnsweredWithAKeepAliveClose(): void
    {
        $ws = new ScriptedWebsocketConnection();

        new NoticeMessageHandler(new NullLogger())->handle(NoticeMessage::fromString(" \t\n\r\v\0ping\0\v\r\n\t "), $this->session($ws));

        self::assertSame(['["CLOSE","keepalive"]'], $ws->sentTexts);
    }

    public function testAPingNoticeEndingInANoBreakSpaceIsNotAnswered(): void
    {
        $ws = new ScriptedWebsocketConnection();

        new NoticeMessageHandler(new NullLogger())->handle(NoticeMessage::fromString("ping\u{00A0}"), $this->session($ws));

        self::assertSame([], $ws->sentTexts);
    }

    private function session(ScriptedWebsocketConnection $ws): RelaySession
    {
        $relayUrl = RelayUrl::tryFromString('wss://relay.test') ?? self::fail('invalid relay URL');

        return new RelaySession(new RelayConnection($relayUrl, ConnectionState::CONNECTED, new ConnectionConfig()), $ws);
    }
}
