# innis/nostr-client

**AMPHP-based async WebSocket client for Nostr protocol**

A PHP client library for connecting to Nostr relays over WebSocket, subscribing to events, and publishing. Built with AMPHP for non-blocking concurrent relay connections and clean architecture principles.

---

## Features

- **Multi-relay connections** - Connect to multiple relays concurrently
- **AMPHP async** - Non-blocking WebSocket I/O with fibers
- **Subscription management** - Subscribe with filters, receive events via handler callbacks
- **Event publishing** - Publish signed events with OK response handling
- **Connection lifecycle** - Automatic state tracking, health checks, reconnection
- **Keep-alive handling** - WebSocket heartbeats and application-level ping responses
- **PSR-3 logging** - Standard logging interface throughout
- **Clean Architecture** - Strict layer separation with domain objects from `innis/nostr-core`

---

## Requirements

- PHP 8.3 or higher
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
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
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

$filter = new Filter(kinds: [EventKind::textNote()], limit: 10);
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

```php
$results = $client->healthCheck();

foreach ($results as $relayUrl => $result) {
    if ($result->isHealthy()) {
        echo "{$relayUrl}: {$result->getLatencyMs()}ms\n";
    } else {
        echo "{$relayUrl}: {$result->getErrorMessage()}\n";
    }
}
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

The client throws on failure. Retry logic belongs in your application layer where you have full business context.

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
    Enum/ConnectionState                 State machine (connected/disconnected/failed)
    ValueObject/ConnectionConfig         Connection configuration
    ValueObject/HealthCheckResult        Health check outcome
    ValueObject/HealthCheckResultCollection  Typed health result collection
    Service/AuthChallengeHandlerInterface    NIP-42 auth callback (application provides)
    Service/RelayHealthCheckerInterface      Standalone health check contract
    Exception/ClientException            Base exception (extends NostrException)
    Exception/ConnectionException        Connection-specific errors
  Infrastructure/
    Connection/AmphpRelayConnection  WebSocket connection handler (AMPHP)
    Connection/ConnectionFactory     WebSocket connection creation
    Connection/ActiveWebSocket       Active WebSocket holder
    Service/ConnectionManager        Implements NostrClientInterface
    Service/WebSocketHealthChecker   Standalone relay health checker
    Factory/NostrClientFactory       Dependency wiring
```

---

## Testing

```bash
# Run tests and static analysis
composer test

# Run unit tests only
composer test-unit

# Run PHPStan analysis (level 9)
composer analyse

# Fix code style
composer fix-style
```

---

## Licence

MIT License. See LICENSE file for details.
