<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\Cancellation;
use Amp\Future;
use Amp\Http\Client\ApplicationInterceptor;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Socket\ConnectContext;
use Amp\Socket\TlsException;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\ConstantRateLimit;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Throwable;

use function Amp\async;

final class ConnectionFactory
{
    private const int HEARTBEAT_PERIOD_SECONDS = 10;
    private const int QUEUED_PING_LIMIT = 2;
    private const int MESSAGE_SIZE_LIMIT_BYTES = 256 * 1024;
    private const int BYTES_PER_SECOND_LIMIT = 2 * 1024 * 1024;
    private const int FRAMES_PER_SECOND_LIMIT = 200;

    private WebsocketConnector $connector;

    public function __construct(?WebsocketConnector $connector = null)
    {
        $this->connector = $connector ?? self::createDefaultConnector();
    }

    public function createConnection(RelayUrl $relayUrl, ConnectionConfig $config, ?Cancellation $cancellation = null): Future
    {
        return async(function () use ($relayUrl, $config, $cancellation) {
            try {
                $handshake = $this->createHandshake($relayUrl, $config);

                return $this->connector->connect($handshake, $cancellation);
            } catch (Throwable $e) {
                if ($this->isTlsError($e)) {
                    $this->connector = self::createDefaultConnector();
                }

                throw ConnectionException::forRelay($relayUrl, 'Failed to establish WebSocket connection', $e);
            }
        });
    }

    private function isTlsError(Throwable $e): bool
    {
        if ($e instanceof TlsException) {
            return true;
        }

        if (null !== $e->getPrevious()) {
            return $this->isTlsError($e->getPrevious());
        }

        return str_contains($e->getMessage(), 'TLS negotiation failed');
    }

    private function createHandshake(RelayUrl $relayUrl, ConnectionConfig $config): WebsocketHandshake
    {
        $handshake = new WebsocketHandshake((string) $relayUrl);

        foreach ($config->getHeaders() as $name => $value) {
            if (is_string($name) && '' !== $name) {
                $handshake = $handshake->withHeader($name, $value);
            }
        }

        if (null !== $config->getUserAgent()) {
            $handshake = $handshake->withHeader('User-Agent', $config->getUserAgent());
        }

        $handshake = $handshake->withTcpConnectTimeout($config->getConnectionTimeoutSeconds());
        $handshake = $handshake->withTlsHandshakeTimeout($config->getConnectionTimeoutSeconds());

        return $handshake;
    }

    private static function createDefaultConnector(): Rfc6455Connector
    {
        $httpClient = (new HttpClientBuilder())
            ->usingPool(
                new UnlimitedConnectionPool(
                    new DefaultConnectionFactory(connectContext: (new ConnectContext())->withTcpNoDelay())
                )
            )
            ->intercept(new class implements ApplicationInterceptor {
                public function request(
                    Request $request,
                    Cancellation $cancellation,
                    DelegateHttpClient $httpClient,
                ): Response {
                    $request->setInactivityTimeout(0);
                    $request->setTransferTimeout(0);

                    return $httpClient->request($request, $cancellation);
                }
            })
            ->build();

        $heartbeatQueue = new PeriodicHeartbeatQueue(
            queuedPingLimit: self::QUEUED_PING_LIMIT,
            heartbeatPeriod: self::HEARTBEAT_PERIOD_SECONDS,
        );
        $parserFactory = new Rfc6455ParserFactory(
            messageSizeLimit: self::MESSAGE_SIZE_LIMIT_BYTES,
            validateUtf8: false,
        );
        $rateLimit = new ConstantRateLimit(
            bytesPerSecondLimit: self::BYTES_PER_SECOND_LIMIT,
            framesPerSecondLimit: self::FRAMES_PER_SECOND_LIMIT,
        );
        $connectionFactory = new Rfc6455ConnectionFactory(
            heartbeatQueue: $heartbeatQueue,
            rateLimit: $rateLimit,
            parserFactory: $parserFactory,
        );

        return new Rfc6455Connector(connectionFactory: $connectionFactory, httpClient: $httpClient);
    }
}
