# innis/nostr-client

[![CI](https://github.com/johninnis/nostr-client/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/nostr-client/actions/workflows/ci.yml)

**AMPHP-based async WebSocket client for Nostr protocol**

A PHP client library for connecting to Nostr relays over WebSocket, subscribing to events, and publishing. Built with AMPHP for non-blocking concurrent relay connections and clean architecture principles.

---

## Features

- **Multi-relay connections** - Connect to multiple relays concurrently
- **AMPHP async** - Non-blocking WebSocket I/O with fibers
- **Subscription management** - Subscribe with single or multiple filters, receive events via handler callbacks
- **Event publishing** - Publish signed events with OK response handling
- **NIP-42 authentication** - Automatic auth challenge handling with transparent publish retry
- **Connection lifecycle** - Automatic state tracking, health checks, reconnection, ping
- **Keep-alive handling** - WebSocket heartbeats and application-level ping responses
- **PSR-3 logging** - Standard logging interface throughout
- **Clean Architecture** - Strict layer separation with domain objects from `innis/nostr-core`

---

## Requirements

- PHP 8.4 or higher
- `innis/nostr-core` - Core Nostr protocol entities
- `amphp/amp` ^3.0 - Async runtime
- `amphp/websocket-client` ^2.0 - WebSocket client
- `psr/log` ^3.0 - Logging interface

---

## Installation

```bash
composer require innis/nostr-client
```

---

## Quick Start

### Connect and Subscribe

```php
use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

$client = NostrClientFactory::create();

$client->connect(RelayUrl::fromString('wss://relay.damus.io'));
$client->connect(RelayUrl::fromString('wss://nos.lol'));

$handler = new class implements EventHandlerInterface {
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        echo substr((string) $event->getContent(), 0, 100)."\n";
    }

    public function handleEose(SubscriptionId $subscriptionId): void {}
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void {}
    public function handleNotice(RelayUrl $relayUrl, string $message): void {}
};

$filter = new Filter(kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]), limit: 10);
$relay = RelayUrl::fromString('wss://relay.damus.io');

$subscriptionId = $client->subscribe($relay, $filter, $handler);

\Amp\delay(5);

$client->unsubscribe($relay, $subscriptionId);
$client->close();
```

### Publish Events

```php
use Innis\Nostr\Core\Domain\Factory\EventFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;

$keyPair = KeyPair::generate();
$event = EventFactory::createTextNote($keyPair->getPublicKey(), 'Hello Nostr!');
$signedEvent = $event->sign($keyPair->getPrivateKey());

$client->publishEvent($relay, $signedEvent);
```

### Health Checking

`healthCheck()` pings every currently connected relay over its existing connection and reports per-relay
latency or failure. To probe a relay you are not connected to, use the standalone health checker below.

```php
$results = $client->healthCheck();

foreach ($results as $result) {
    $relayUrl = $result->getRelayUrl();
    if ($result->isHealthy()) {
        echo "{$relayUrl}: {$result->getLatencyMs()}ms\n";
    } else {
        echo "{$relayUrl}: {$result->getErrorMessage()}\n";
    }
}
```

### Multiple Filters Per Subscription

```php
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;

$subscriptionId = $client->subscribeMultiple(
    $relay,
    new FilterCollection([
        new Filter(kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]), limit: 10),
        new Filter(kinds: EventKindCollection::fromInts([EventKind::REACTION]), limit: 10),
    ]),
    $handler,
);
```

### Connection Configuration

`connect()` accepts an optional `ConnectionConfig`. It controls the connection timeout, request
headers, user agent, and auto-reconnect behaviour. It is immutable; build variants with the `with*`
methods.

```php
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;

$config = (new ConnectionConfig())
    ->withConnectionTimeout(15)
    ->withHeaders(['Authorization' => 'Bearer token'])
    ->withUserAgent('my-app/1.0')
    ->withAutoReconnect(true)
    ->withReconnectDelays(initialMs: 500, maxMs: 60000)
    ->withReconnectMaxAttempts(0);

$client->connect($relay, $config);
```

Auto-reconnect is enabled by default. A dropped connection retries on jittered exponential backoff
between `reconnectInitialDelayMs` and `reconnectMaxDelayMs`. `reconnectMaxAttempts` of `0` means
unlimited retries; a positive value bounds them.

### Connection Management

```php
$client->reconnect($relay);
$client->disconnect($relay);
$client->ping($relay);

$state = $client->getConnectionStatus($relay);
$isConnected = $client->isConnected($relay);

$connection = $client->getConnection($relay);
$connected = $client->getConnectedRelays();
$all = $client->getAllConnections();
```

`getConnectionStatus()` returns a `ConnectionState`: `DISCONNECTED`, `CONNECTED`, `DISCONNECTING`, or
`FAILED`.

### Reconnection Listener

Register a listener to re-establish per-connection state (re-subscribe, re-authenticate) after a
dropped connection is restored. The listener fires only on a successful reconnect.

```php
use Innis\Nostr\Client\Domain\Service\ReconnectionListenerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

$listener = new class implements ReconnectionListenerInterface {
    public function onReconnected(RelayUrl $relayUrl): void
    {
        // resubscribe, reauthenticate, etc.
    }
};

$client->setReconnectionListener($listener);
```

### Awaiting Publishes

`publishEvent()` returns once the event has been sent. To block until the relay has acknowledged all
in-flight publishes for a relay (including any parked on a NIP-42 auth challenge), await them with an
optional timeout in seconds.

```php
$client->publishEvent($relay, $signedEvent);
$client->awaitPendingPublishes($relay, timeoutSeconds: 5.0);
```

### NIP-42 Authentication

Register an auth handler to sign relay challenges. When `publishEvent()` is rejected with `auth-required`, the client completes the challenge-response flow and retransmits the queued event transparently.

```php
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Domain\Factory\EventFactory;

$authHandler = new class($keyPair) implements AuthChallengeHandlerInterface {
    public function __construct(private KeyPair $keyPair) {}

    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
    {
        $event = EventFactory::createAuth($this->keyPair->getPublicKey(), $relayUrl, $challenge);

        return $event->sign($this->keyPair->getPrivateKey());
    }
};

$client->setAuthHandler($authHandler);
```

### Standalone Health Checker

Check relay health without an active connection:

```php
$healthChecker = NostrClientFactory::createHealthChecker();
$result = $healthChecker->checkHealth(RelayUrl::fromString('wss://relay.damus.io'));
```

See [`examples/`](examples/) for complete working examples.

---

## Error Handling

Anticipated outcomes (a well-formed operation whose answer is "no") are returned as typed values (`?T` or a `*Failure`); faults are thrown. nostr-client's faults are `ClientException` (abstract) extending `NostrException`, with `ConnectionException` (final) extending `ClientException`. Catch `NostrException` to handle faults from any `nostr-*` library, or `ConnectionException` for connection faults specifically. See [ADR-0002](docs/adr/0002-clientexception-roots-nostr-client-faults-under-nostrexception.md) for how faults are rooted.

Retry logic belongs in your application layer where you have full business context.

```php
try {
    $client->publishEvent($relay, $event);
} catch (\Throwable $e) {
    $this->logger->error('Publish failed', [
        'relay' => (string) $relay,
        'error' => $e->getMessage(),
    ]);
}
```

---

## Architecture

This package follows Clean Architecture principles:

```
src/
  Application/
    Port/NostrClientInterface        Public API contract
    Port/ConnectionHandlerInterface  Infrastructure port
  Domain/
    Entity/RelayConnection               Connection state and subscriptions
    Entity/RelayConnectionCollection     Typed connection collection
    Enum/ConnectionState                 State machine (disconnected/connected/disconnecting/failed)
    ValueObject/ConnectionConfig         Connection configuration
    ValueObject/HealthCheckResult        Health check outcome
    ValueObject/HealthCheckResultCollection  Typed health result collection
    Service/AuthChallengeHandlerInterface    NIP-42 auth callback (application provides)
    Service/ReconnectionListenerInterface    Reconnect-succeeded callback (application provides)
    Service/RelayHealthCheckerInterface      Standalone health check contract
    Exception/ClientException            Base exception (extends NostrException)
    Exception/ConnectionException        Connection-specific errors
  Infrastructure/
    Connection/AmphpRelayConnection      WebSocket connection handler (AMPHP)
    Connection/ConnectionFactory         WebSocket connection creation
    Connection/ActiveWebSocket           Active WebSocket holder
    Connection/ParkedPublish             Publish parked on a NIP-42 auth challenge
    Connection/ConnectionManager         Implements NostrClientInterface
    Connection/WebSocketHealthChecker    Standalone relay health checker
    Factory/NostrClientFactory           Dependency wiring
```

---

## Testing

```bash
# Run tests and static analysis
composer test

# Run unit tests only
composer test-unit

# Run tests with coverage reports
composer test-coverage

# Run PHPStan analysis (level 9)
composer analyse

# Fix code style
composer fix-style

# Check code style without modifying files
composer check-style

# Apply Rector transformations
composer rector

# Check Rector transformations without modifying files
composer check-rector
```

---

## Licence

MIT License. See LICENSE file for details.
