<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Factory;

use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Application\Port\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionManager;
use Innis\Nostr\Client\Infrastructure\Connection\WebSocketHealthChecker;
use Innis\Nostr\Core\Infrastructure\Encoding\JsonMessageDeserialiser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class NostrClientFactory
{
    public static function create(LoggerInterface $logger = new NullLogger()): NostrClientInterface
    {
        $connectionFactory = new ConnectionFactory();
        $deserialiser = new JsonMessageDeserialiser();
        $amphpConnection = new AmphpRelayConnection($connectionFactory, $deserialiser, $logger);

        return new ConnectionManager($amphpConnection, $logger);
    }

    public static function createHealthChecker(LoggerInterface $logger = new NullLogger()): RelayHealthCheckerInterface
    {
        return new WebSocketHealthChecker(new ConnectionFactory(), $logger);
    }
}
