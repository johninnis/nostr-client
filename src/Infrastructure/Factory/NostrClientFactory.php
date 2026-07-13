<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Factory;

use Innis\Nostr\Client\Application\Port\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Application\Service\MultiRelayNostrClient;
use Innis\Nostr\Client\Application\Service\NostrClientInterface;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Infrastructure\Connection\WebsocketHealthChecker;
use Innis\Nostr\Core\Domain\Service\JsonMessageDeserialiser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class NostrClientFactory
{
    public static function create(LoggerInterface $logger = new NullLogger()): NostrClientInterface
    {
        $connectionFactory = new ConnectionFactory();
        $deserialiser = new JsonMessageDeserialiser();
        $amphpConnection = new AmphpRelayConnection($connectionFactory, $deserialiser, $logger);

        return new MultiRelayNostrClient($amphpConnection, $logger);
    }

    public static function createHealthChecker(LoggerInterface $logger = new NullLogger()): RelayHealthCheckerInterface
    {
        return new WebsocketHealthChecker(new ConnectionFactory(), $logger);
    }
}
