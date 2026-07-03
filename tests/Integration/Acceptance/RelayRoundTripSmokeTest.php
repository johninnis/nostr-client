<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Integration\Acceptance;

use Innis\Nostr\Client\Infrastructure\Factory\NostrClientFactory;
use Innis\Nostr\Client\Tests\Support\CapturingEventHandler;
use Innis\Nostr\Client\Tests\Support\InMemoryRelayEventStore;
use Innis\Nostr\Client\Tests\Support\LoopbackRelayConfig;
use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayInstance;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end smoke test: stand up a real innis/nostr-relay on loopback, then drive it
 * with the real client over a real WebSocket — publish a signed event and read it back
 * through a subscription. This is the one test that proves the client talks to an actual
 * relay, not a scripted socket double.
 */
final class RelayRoundTripSmokeTest extends TestCase
{
    private const float ROUND_TRIP_TIMEOUT_SECONDS = 5.0;

    public function testAnEventPublishedToARealRelayIsReceivedBackOverASubscription(): void
    {
        if (!class_exists(RelayServerFactory::class)) {
            self::markTestSkipped('innis/nostr-relay (dev dependency) is not installed');
        }

        $store = new InMemoryRelayEventStore();
        $relay = $this->startRelay($store);
        $client = NostrClientFactory::create();

        try {
            $address = $relay->getListeningAddress() ?? self::fail('relay is not listening');
            $relayUrl = RelayUrl::tryFromString('ws://'.$address->toString()) ?? self::fail('invalid relay URL');

            $client->connect($relayUrl);
            self::assertTrue($client->isConnected($relayUrl));

            $signer = Secp256k1Signer::create();
            $keyPair = KeyPair::generate($signer);
            $event = RumourFactory::createTextNote($keyPair->getPublicKey(), 'nostr-client smoke test')->sign($keyPair, $signer);

            $publish = $client->publishEvent($relayUrl, $event)->await();
            self::assertTrue($publish->isAccepted(), 'the relay accepted the event: '.$publish->getMessage());

            $handler = new CapturingEventHandler();
            $filter = new Filter(
                authors: PublicKeyCollection::fromHexValues([$keyPair->getPublicKey()->toHex()]),
                kinds: EventKindCollection::fromInts([EventKind::TEXT_NOTE]),
            );
            $client->subscribe($relayUrl, $filter, $handler);

            $received = $handler->awaitFirstEvent(self::ROUND_TRIP_TIMEOUT_SECONDS);

            self::assertSame($event->getId()->toHex(), $received->getId()->toHex());
        } finally {
            $client->close();
            $relay->stop();
        }
    }

    private function startRelay(InMemoryRelayEventStore $store): RelayInstance
    {
        $config = new LoopbackRelayConfig();
        $logger = new NullLogger();
        $authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
        $policyConfig = RelayPolicyConfig::tryFromArray([]) ?? self::fail('invalid relay policy configuration');

        $relay = new RelayServerFactory(
            eventStore: $store,
            policy: new RelayPolicy($authenticationRegistry, $logger, $policyConfig),
            config: $config,
            rateLimitPolicy: new StaticRateLimitPolicy(new RateLimitConfig(eventsPerMinute: 600, subscriptionsPerMinute: 600)),
            authenticationRegistry: $authenticationRegistry,
            logger: $logger,
            nip11InfoProvider: new StaticNip11InfoProvider(
                Nip11Info::fromArray($config->getRelayUrl(), ['name' => 'nostr-client smoke test', 'supported_nips' => [1, 11]]),
            ),
        )->create();

        $relay->start();

        return $relay;
    }
}
