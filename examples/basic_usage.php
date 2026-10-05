<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
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
    $relay = RelayUrl::tryFromString($url);
    if (null === $relay) {
        echo "Invalid relay URL: {$url}\n";
        continue;
    }
    $result = $client->connect($relay);

    if (!$result->isConnected()) {
        echo "{$url}: {$result->getMessage()}\n";
        continue;
    }

    $connectedRelays[] = $relay;
    echo "Connected to: {$url}\n";
}

if ([] === $connectedRelays) {
    echo "No relays available\n";
    exit(1);
}

$handler = new class implements EventHandlerInterface {
    private int $eventCount = 0;

    #[Override]
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        ++$this->eventCount;
        echo "Event {$this->eventCount}: ".substr((string) $event->getContent(), 0, 100)."\n";
    }

    #[Override]
    public function handleEose(SubscriptionId $subscriptionId): void
    {
        echo "End of stored events for: {$subscriptionId}\n";
    }

    #[Override]
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        echo "Subscription closed: {$message}\n";
    }

    #[Override]
    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        echo "Notice from {$relayUrl}: {$message}\n";
    }
};

$filter = Filter::from(
    kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]),
    limit: 10
);

$relay = $connectedRelays[0];
echo "Subscribing to text notes on {$relay}...\n";
$subscriptionId = $client->subscribe(SubscriptionRequest::for($relay, $filter), $handler);

\Amp\delay(5);

$client->unsubscribe($relay, $subscriptionId);
echo "Subscription closed\n";

$client->close();
echo "Client closed\n";
