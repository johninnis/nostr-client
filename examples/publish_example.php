<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

$client = NostrClientFactory::create();

$signer = Secp256k1Signer::create();
$keyPair = KeyPair::generate($signer);

$event = Rumour::draft($keyPair->getPublicKey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('Hello from innis/nostr-client'))
    ->sign($keyPair, $signer);

echo "Publishing event {$event->getId()->toHex()}\n";

$relays = [
    'wss://relay.damus.io',
    'wss://nos.lol',
];

foreach ($relays as $url) {
    $relay = RelayUrl::tryFromString($url);
    if (null === $relay) {
        echo "Invalid relay URL: {$url}\n";
        continue;
    }

    $connected = $client->connect($relay);

    if (!$connected->isConnected()) {
        echo "{$url}: {$connected->getMessage()}\n";
        continue;
    }

    $result = $client->publishEvent($relay, $event)->await();

    if ($result->isAccepted()) {
        echo "{$url}: accepted\n";
    } else {
        echo "{$url}: not stored ({$result->getMessage()})\n";
    }
}

$client->close();
echo "Done\n";
