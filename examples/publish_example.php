<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

$client = NostrClientFactory::create();

$signer = Secp256k1Signer::create();
$keyPair = KeyPair::generate($signer);

$event = RumourFactory::createTextNote($keyPair->getPublicKey(), 'Hello from innis/nostr-client')
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

    try {
        $client->connect($relay);
    } catch (Throwable $e) {
        echo "{$url}: failed to connect ({$e->getMessage()})\n";
        continue;
    }

    try {
        $result = $client->publishEvent($relay, $event)->await();

        if ($result->isAccepted()) {
            echo "{$url}: accepted\n";
        } else {
            echo "{$url}: rejected ({$result->getMessage()})\n";
        }
    } catch (Throwable $e) {
        echo "{$url}: connection fault ({$e->getMessage()})\n";
    }
}

$client->close();
echo "Done\n";
