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
    'wss://relay.snort.social',
];

$connectedRelays = [];
foreach ($relays as $url) {
    $relay = RelayUrl::fromString($url);
    try {
        $client->connect($relay);
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

$handler = new class implements EventHandlerInterface {
    private int $eventCount = 0;

    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        ++$this->eventCount;
        echo "Event {$this->eventCount}: ".substr((string) $event->getContent(), 0, 100)."\n";
    }

    public function handleEose(SubscriptionId $subscriptionId): void
    {
        echo "End of stored events for: {$subscriptionId}\n";
    }

    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        echo "Subscription closed: {$message}\n";
    }

    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        echo "Notice from {$relayUrl}: {$message}\n";
    }
};

$filter = new Filter(
    kinds: [EventKind::textNote()],
    limit: 10
);

$relay = RelayUrl::fromString($connectedRelays[0]);
echo "Subscribing to text notes on {$connectedRelays[0]}...\n";
$subscriptionId = $client->subscribe($relay, $filter, $handler);

\Amp\delay(5);

$client->unsubscribe($relay, $subscriptionId);
echo "Subscription closed\n";

$client->close();
echo "Client closed\n";
