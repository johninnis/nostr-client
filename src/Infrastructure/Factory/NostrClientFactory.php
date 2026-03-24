<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Factory;

use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Infrastructure\Service\ConnectionManager;
use Innis\Nostr\Client\Infrastructure\Service\WebSocketHealthChecker;
use Innis\Nostr\Core\Infrastructure\Adapter\JsonMessageSerialiserAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class NostrClientFactory
{
    public static function create(LoggerInterface $logger = new NullLogger()): NostrClientInterface
    {
        $connectionFactory = new ConnectionFactory();
        $serialiser = new JsonMessageSerialiserAdapter();
        $amphpConnection = new AmphpRelayConnection($connectionFactory, $serialiser, $logger);

        return new ConnectionManager($amphpConnection, $logger);
    }

    public static function createHealthChecker(LoggerInterface $logger = new NullLogger()): RelayHealthCheckerInterface
    {
        return new WebSocketHealthChecker(new ConnectionFactory(), $logger);
    }
}
