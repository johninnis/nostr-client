<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\TimeoutCancellation;
use Innis\Nostr\Client\Application\Port\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class WebsocketHealthChecker implements RelayHealthCheckerInterface
{
    private const float DEFAULT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Override]
    public function checkHealth(RelayUrl $relayUrl, float $timeout = self::DEFAULT_TIMEOUT_SECONDS): HealthCheckResult
    {
        try {
            $cancellation = new TimeoutCancellation($timeout);
            $config = new ConnectionConfig(
                connectionTimeoutSeconds: (int) ceil($timeout)
            );

            $websocket = $this->connectionFactory->createConnection($relayUrl, $config, $cancellation);
            $websocket->close();

            $this->logger->debug('Relay health check succeeded', [
                'relay' => (string) $relayUrl,
            ]);

            return HealthCheckResult::success($relayUrl);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();

            $this->logger->debug('Relay health check failed', [
                'relay' => (string) $relayUrl,
                'error' => $errorMessage,
            ]);

            return HealthCheckResult::failure($relayUrl, $errorMessage);
        }
    }
}
