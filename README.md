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

$damus = RelayUrl::tryFromString('wss://relay.damus.io')
    ?? throw new InvalidArgumentException('Invalid relay URL');
$nosLol = RelayUrl::tryFromString('wss://nos.lol')
    ?? throw new InvalidArgumentException('Invalid relay URL');

$client->connect($damus);
$client->connect($nosLol);

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

$subscriptionId = $client->subscribe($damus, $filter, $handler);

\Amp\delay(5);

$client->unsubscribe($damus, $subscriptionId);
$client->close();
```

### Publish Events

```php
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

$signer = Secp256k1Signer::create();
$keyPair = KeyPair::generate($signer);
$signedEvent = RumourFactory::createTextNote($keyPair->getPublicKey(), 'Hello Nostr!')
    ->sign($keyPair, $signer);

// publishEvent() returns a Future<PublishResult>. Await it for the relay's verdict,
// or drop it for fire-and-forget.
$result = $client->publishEvent($relay, $signedEvent)->await();

if ($result->isAccepted()) {
    echo "Stored by the relay\n";
} else {
    echo "Rejected: {$result->getMessage()}\n";
}
```

A relay accepting or rejecting an event (`duplicate`, `rate-limited`, `blocked`, …) is an anticipated
outcome carried in the `PublishResult`; only a broken connection throws.

### Health Checking

`healthCheck()` pings every currently connected relay over its existing connection and reports whether
each is still reachable. To probe a relay you are not connected to, use the standalone health checker
below.

```php
$results = $client->healthCheck();

foreach ($results as $result) {
    $relayUrl = $result->getRelayUrl();
    if ($result->isHealthy()) {
        echo "{$relayUrl}: reachable\n";
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
headers, user agent, and auto-reconnect behaviour. It is immutable; construct it with named
arguments, defaulting anything you do not set.

```php
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;

$config = new ConnectionConfig(
    connectionTimeoutSeconds: 15,
    headers: ['Authorization' => 'Bearer token'],
    userAgent: 'my-app/1.0',
    autoReconnect: true,
    reconnectInitialDelayMs: 500,
    reconnectMaxDelayMs: 60000,
    reconnectMaxAttempts: 0,
);

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
use Innis\Nostr\Client\Application\Port\ReconnectionListenerInterface;
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

`publishEvent()` returns a `Future<PublishResult>` as soon as the event has been sent — await each
future for that publish's individual outcome. To instead block until every in-flight publish for a
relay has been acknowledged (including any parked on a NIP-42 auth challenge) without inspecting each
result, drain them with an optional timeout in seconds.

```php
$client->publishEvent($relay, $eventA);
$client->publishEvent($relay, $eventB);
$client->awaitPendingPublishes($relay, timeoutSeconds: 5.0);
```

### NIP-42 Authentication

Register an auth handler to sign relay challenges. When `publishEvent()` is rejected with `auth-required`, the client completes the challenge-response flow and retransmits the queued event transparently.

```php
use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;

$authHandler = new class($keyPair, $signer) implements AuthChallengeHandlerInterface {
    public function __construct(
        private KeyPair $keyPair,
        private SignatureServiceInterface $signer,
    ) {}

    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
    {
        return RumourFactory::createAuth($this->keyPair->getPublicKey(), $relayUrl, $challenge)
            ->sign($this->keyPair, $this->signer);
    }
};

$client->setAuthHandler($authHandler);
```

### Standalone Health Checker

Check relay health without an active connection:

```php
$healthChecker = NostrClientFactory::createHealthChecker();
$relay = RelayUrl::tryFromString('wss://relay.damus.io')
    ?? throw new InvalidArgumentException('Invalid relay URL');
$result = $healthChecker->checkHealth($relay);
```

See [`examples/`](examples/) for complete working examples.

---

## Error Handling

Anticipated outcomes (a well-formed operation whose answer is "no") are returned as typed values (`?T` or a `*Failure`); faults are thrown. nostr-client's faults are `ClientException` (abstract) extending `NostrException`, with `ConnectionException` (final) extending `ClientException`. Catch `NostrException` to handle faults from any `nostr-*` library, or `ConnectionException` for connection faults specifically. See [ADR-0002](docs/adr/0002-clientexception-roots-nostr-client-faults-under-nostrexception.md) for how faults are rooted.

Retry logic belongs in your application layer where you have full business context.

```php
try {
    $result = $client->publishEvent($relay, $event)->await();

    if (!$result->isAccepted()) {
        // The relay declined the event — an outcome, not a fault.
        $this->logger->info('Relay rejected the event', [
            'relay' => (string) $relay,
            'reason' => $result->getMessage(),
        ]);
    }
} catch (ConnectionException $e) {
    // The connection broke mid-publish — a fault.
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
    Service/NostrClientInterface         Public API contract (driving)
    Service/MultiRelayNostrClient        Orchestrates many relays; implements NostrClientInterface
    Port/ConnectionHandlerInterface      Driven transport port (AmphpRelayConnection implements)
    Port/AuthChallengeHandlerInterface   NIP-42 auth callback (application provides)
    Port/ReconnectionListenerInterface   Reconnect-succeeded callback (application provides)
    Port/RelayHealthCheckerInterface     Standalone health check contract
  Domain/
    Collection/RelayConnectionCollection     Typed connection collection
    Collection/HealthCheckResultCollection   Typed health result collection
    Entity/RelayConnection               Connection state and subscriptions
    Enum/ConnectionState                 State machine (disconnected/connected/disconnecting/failed)
    ValueObject/ConnectionConfig         Connection configuration
    ValueObject/HealthCheckResult        Health check outcome
    ValueObject/PublishResult            Relay accept/reject verdict on a publish
    Exception/ClientException            Base exception (extends NostrException)
    Exception/ConnectionException        Connection-specific errors
  Infrastructure/
    Connection/AmphpRelayConnection      Transport port implementation (AMPHP); drives the collaborators below
    Connection/ConnectionFactory         WebSocket connection creation
    Connection/RelaySession              Per-relay live state (socket, handlers, pending)
    Connection/RelaySessionRegistry      Per-relay sessions, generations and reconnect cancellations
    Connection/InboundMessageDispatcher  Deserialises a frame and routes it to the matching handler
    Connection/EventMessageHandler       Inbound EVENT/OK/EOSE/CLOSED/NOTICE/AUTH handlers
    Connection/ConnectionErrorHandler    Fails a connection: notifies subscribers, errors pending publishes
    Connection/ParkedPublish             Publish parked on a NIP-42 auth challenge
    Connection/WebsocketHealthChecker    Standalone relay health checker
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
