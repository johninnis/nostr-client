<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;

$client = NostrClientFactory::create();

$relays = [
    'wss://relay.damus.io',
    'wss://nos.lol',
];

$connectedRelays = [];
foreach ($relays as $url) {
    try {
        $client->connect(RelayUrl::fromString($url));
        $connectedRelays[] = $url;
        echo "Connected to: {$url}\n";
    } catch (\Throwable $e) {
        echo "Failed to connect to {$url}: {$e->getMessage()}\n";
    }
}

if ($connectedRelays === []) {
    echo "No relays available\n";
    exit(1);
}

$eventCount = 0;

$handler = new class($eventCount) implements EventHandlerInterface {
    public function __construct(private int &$eventCount)
    {
    }

    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        ++$this->eventCount;
        echo '[EVENT] '.substr((string) $event->getContent(), 0, 80)."\n";
    }

    public function handleEose(SubscriptionId $subscriptionId): void
    {
        echo "[EOSE] End of stored events for: {$subscriptionId}\n";
    }

    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        echo "[CLOSED] Subscription {$subscriptionId}: {$message}\n";
    }

    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        echo "[NOTICE] {$relayUrl}: {$message}\n";
    }
};

$filter = new Filter(
    kinds: [EventKind::textNote()],
    limit: 50
);

echo "Starting stream...\n";

$subscriptionIds = [];
foreach ($connectedRelays as $url) {
    $relay = RelayUrl::fromString($url);
    $subscriptionIds[] = ['relay' => $relay, 'id' => $client->subscribe($relay, $filter, $handler)];
}

echo "Streaming for 10 seconds...\n";
\Amp\delay(10);

echo "Stopping stream...\n";
foreach ($subscriptionIds as $sub) {
    $client->unsubscribe($sub['relay'], $sub['id']);
}

echo "Total events received: {$eventCount}\n";

$client->close();
echo "Done\n";
