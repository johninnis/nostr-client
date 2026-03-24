<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Service;

use Amp\TimeoutCancellation;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class WebSocketHealthChecker implements RelayHealthCheckerInterface
{
    private const float DEFAULT_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function checkHealth(RelayUrl $relayUrl, float $timeout = self::DEFAULT_TIMEOUT_SECONDS): HealthCheckResult
    {
        $startTime = microtime(true);

        try {
            $cancellation = new TimeoutCancellation($timeout);
            $config = new ConnectionConfig(
                connectionTimeoutSeconds: (int) ceil($timeout)
            );

            $websocket = $this->connectionFactory->createConnection($relayUrl, $config, $cancellation)->await();
            $websocket->close();

            $latencyMs = (microtime(true) - $startTime) * 1000;

            $this->logger->debug('Relay health check succeeded', [
                'relay' => (string) $relayUrl,
                'latency_ms' => round($latencyMs, 2),
            ]);

            return HealthCheckResult::success($latencyMs);
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();

            $this->logger->debug('Relay health check failed', [
                'relay' => (string) $relayUrl,
                'error' => $errorMessage,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return HealthCheckResult::failure($errorMessage);
        }
    }
}
