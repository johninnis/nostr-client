<?php

declare(strict_types=1);

/*
 * Soak + hostile-relay harness.
 *
 * Drives the full client facade against relays that stream adversarial frames
 * (malformed JSON, wrong-typed arrays, unknown message types, oversized payloads,
 * auth challenges, keep-alive pings, floods) and drop mid-session, under sustained
 * randomised operation churn with auto-reconnect.
 *
 * Invariants checked:
 *   - only a ConnectionException may escape a client operation; any other throwable
 *     is a defect and fails the run;
 *   - the process completes every iteration (no hang, no fatal);
 *   - live connections never exceed the relay count (no session leak);
 *   - resident memory does not trend upward across the run (no unbounded retention).
 *
 * Usage: php tools/soak-harness.php [iterations] [relays] [seed]
 */

require __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Client\Application\Port\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Service\MultiRelayNostrClient;
use Innis\Nostr\Client\Domain\Exception\ConnectionException;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Client\Infrastructure\Connection\AmphpRelayConnection;
use Innis\Nostr\Client\Infrastructure\Connection\ConnectionFactory;
use Innis\Nostr\Client\Tests\Support\ScriptedWebsocketConnection;
use Innis\Nostr\Client\Tests\Support\SuppliedWebsocketConnector;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\JsonMessageDeserialiser;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;

use function Amp\async;
use function Amp\delay;

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 20000;
$relayCount = isset($argv[2]) ? max(1, (int) $argv[2]) : 6;
$seed = isset($argv[3]) ? (int) $argv[3] : 20260701;

mt_srand($seed);

$signer = Secp256k1Signer::create();
$keyPair = KeyPair::generate($signer);

/**
 * A relay-supplied frame, drawn adversarially. A valid subscription id is threaded in
 * so some frames legitimately match a live subscription and exercise the delivery path.
 */
$hostileFrame = static function (string $subscriptionId) use ($keyPair): string {
    $eventId = str_pad(dechex(mt_rand()), 64, '0', STR_PAD_LEFT);
    $pubkey = $keyPair->getPublicKey()->toHex();
    $validEvent = sprintf(
        '{"id":"%s","pubkey":"%s","created_at":%d,"kind":1,"tags":[],"content":"soak"}',
        $eventId,
        $pubkey,
        1_700_000_000 + mt_rand(0, 100000),
    );

    $menu = [
        sprintf('["EVENT","%s",%s]', $subscriptionId, $validEvent),
        sprintf('["OK","%s",true,""]', $eventId),
        sprintf('["OK","%s",false,"blocked: policy"]', $eventId),
        sprintf('["OK","%s",false,"auth-required: authenticate first"]', $eventId),
        sprintf('["EOSE","%s"]', $subscriptionId),
        sprintf('["CLOSED","%s","done"]', $subscriptionId),
        '["NOTICE","a routine notice"]',
        '["NOTICE","ping"]',
        '["AUTH","challenge-'.mt_rand().'"]',
        '["COUNT","'.$subscriptionId.'",{"count":3}]',
        // Malformed / adversarial:
        '{',
        '',
        'not json at all',
        '["unterminated',
        '[]',
        '["EVENT"]',
        '["OK"]',
        '["EVENT",123,{}]',
        '["OK","not-hex",true,""]',
        '12345',
        'true',
        '{"an":"object"}',
        '["WAT_UNKNOWN",1,2,3]',
        sprintf('["NOTICE","%s"]', str_repeat('x', 300000)),
        '["EVENT","'.$subscriptionId.'",{"kind":"not-an-int","pubkey":"'.$pubkey.'"}]',
    ];

    return $menu[mt_rand(0, count($menu) - 1)];
};

/**
 * Each connect() yields a fresh relay socket that streams a bounded burst of hostile
 * frames and, with some probability, drops the connection. A new socket is produced on
 * every reconnect, so churn drives an unbounded number of short-lived hostile relays.
 */
$currentSubscriptionId = 'sub-soak';
$connector = new SuppliedWebsocketConnector(static function () use (&$hostileFrame, &$currentSubscriptionId): ScriptedWebsocketConnection {
    $socket = new ScriptedWebsocketConnection();
    $burst = mt_rand(0, 40);
    $shouldDrop = 1 === mt_rand(0, 3);

    async(static function () use ($socket, $burst, $shouldDrop, $hostileFrame, &$currentSubscriptionId): void {
        for ($frame = 0; $frame < $burst; ++$frame) {
            try {
                $socket->pushInbound($hostileFrame($currentSubscriptionId));
            } catch (Throwable) {
                return; // stream closed by the client
            }

            if (0 === mt_rand(0, 5)) {
                delay(0);
            }
        }

        if ($shouldDrop) {
            try {
                $socket->close();
            } catch (Throwable) {
            }
        }
    })->ignore();

    return $socket;
});

$client = new MultiRelayNostrClient(
    new AmphpRelayConnection(new ConnectionFactory($connector), new JsonMessageDeserialiser()),
);

$client->setAuthHandler(new class($keyPair, $signer) implements AuthChallengeHandlerInterface {
    public function __construct(
        private readonly KeyPair $keyPair,
        private readonly SignatureServiceInterface $signer,
    ) {
    }

    #[Override]
    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
    {
        // Half the time decline, to exercise both the retry-flush and the no-signed-event paths.
        if (0 === mt_rand(0, 1)) {
            return null;
        }

        return RumourFactory::createAuth($this->keyPair->getPublicKey(), $relayUrl, $challenge)
            ->sign($this->keyPair, $this->signer);
    }
});

$handler = new class implements EventHandlerInterface {
    public int $events = 0;
    public int $notices = 0;

    #[Override]
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        ++$this->events;
    }

    #[Override]
    public function handleEose(SubscriptionId $subscriptionId): void
    {
    }

    #[Override]
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
    }

    #[Override]
    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        ++$this->notices;
    }
};

/** @var list<RelayUrl> $relays */
$relays = [];
for ($r = 0; $r < $relayCount; ++$r) {
    $url = RelayUrl::fromString(sprintf('wss://relay-%d.test', $r));
    if (null !== $url) {
        $relays[] = $url;
    }
}

$config = static fn (): ConnectionConfig => new ConnectionConfig(
    autoReconnect: 1 === mt_rand(0, 1),
    reconnectInitialDelayMs: 1,
    reconnectMaxDelayMs: 5,
    reconnectMaxAttempts: mt_rand(0, 3),
);

$operations = ['connect', 'disconnect', 'reconnect', 'subscribe', 'unsubscribe', 'publish', 'ping', 'health', 'close'];

/** @var list<array{iteration: int, operation: string, error: string}> $violations */
$violations = [];
/** @var array<string, int> $opCounts */
$opCounts = [];
/** @var list<int> $memorySamples */
$memorySamples = [];
$connectionExceptions = 0;
$maxConnections = 0;
$lastSubscriptionId = null;

$started = hrtime(true);

for ($step = 0; $step < $iterations; ++$step) {
    $relay = $relays[mt_rand(0, count($relays) - 1)];
    $operation = $operations[mt_rand(0, count($operations) - 1)];
    $opCounts[$operation] = ($opCounts[$operation] ?? 0) + 1;

    try {
        switch ($operation) {
            case 'connect':
                $client->connect($relay, $config());
                break;
            case 'disconnect':
                $client->disconnect($relay);
                break;
            case 'reconnect':
                $client->reconnect($relay);
                break;
            case 'subscribe':
                $lastSubscriptionId = SubscriptionId::generate();
                $currentSubscriptionId = (string) $lastSubscriptionId;
                $client->subscribe($relay, new Filter(), $handler, $lastSubscriptionId);
                break;
            case 'unsubscribe':
                if (null !== $lastSubscriptionId) {
                    $client->unsubscribe($relay, $lastSubscriptionId);
                }
                break;
            case 'publish':
                $note = RumourFactory::createTextNote($keyPair->getPublicKey(), 'soak note')->sign($keyPair, $signer);
                $client->publishEvent($relay, $note)->ignore();
                break;
            case 'ping':
                $client->ping($relay);
                break;
            case 'health':
                $client->healthCheck();
                break;
            case 'close':
                $client->close();
                break;
        }
    } catch (ConnectionException) {
        ++$connectionExceptions;
    } catch (Throwable $e) {
        $violations[] = [
            'iteration' => $step,
            'operation' => $operation,
            'error' => $e::class.': '.$e->getMessage(),
        ];
    } finally {
        delay(0);
    }

    $maxConnections = max($maxConnections, count($client->getAllConnections()));

    if (0 === $step % 200) {
        gc_collect_cycles();
        $memorySamples[] = memory_get_usage();
    }
}

$client->close();
for ($settle = 0; $settle < 20 && count($client->getConnectedRelays()) > 0; ++$settle) {
    $client->close();
    delay(0.005);
}

gc_collect_cycles();
$leftConnected = count($client->getConnectedRelays());
$elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

// Leak signal: compare the median of the last quarter of samples against the first
// quarter taken after warmup. A steadily-retaining run trends upward; a healthy one is flat.
$median = static function (array $values): int {
    sort($values);
    $count = count($values);

    return 0 === $count ? 0 : (int) $values[intdiv($count, 2)];
};
$sampleCount = count($memorySamples);
$warm = array_slice($memorySamples, (int) ($sampleCount * 0.2));
$warmCount = count($warm);
$earlyMedian = $median(array_slice($warm, 0, max(1, (int) ($warmCount * 0.25))));
$lateMedian = $median(array_slice($warm, (int) ($warmCount * 0.75)));
$growthBytes = $lateMedian - $earlyMedian;
$peakBytes = memory_get_peak_usage();

$mib = static fn (int $bytes): string => number_format($bytes / 1048576, 1).' MiB';

echo "== Soak + hostile-relay harness ==\n";
echo sprintf("seed=%d relays=%d iterations=%d elapsed=%dms\n", $seed, $relayCount, $iterations, $elapsedMs);
echo sprintf("operations: %s\n", json_encode($opCounts, JSON_THROW_ON_ERROR));
echo sprintf("connection-exceptions (expected): %d\n", $connectionExceptions);
echo sprintf("events-delivered=%d notices-delivered=%d\n", $handler->events, $handler->notices);
echo sprintf("max live connections: %d (relay count %d)\n", $maxConnections, $relayCount);
echo sprintf("memory: early=%s late=%s growth=%s peak=%s\n", $mib($earlyMedian), $mib($lateMedian), $mib($growthBytes), $mib($peakBytes));
echo sprintf("connections left after teardown: %d\n", $leftConnected);

$leak = $growthBytes > 16 * 1048576;
$connectionLeak = $maxConnections > $relayCount;
$ok = [] === $violations && !$leak && !$connectionLeak && 0 === $leftConnected;

if ([] !== $violations) {
    echo "\nINVARIANT VIOLATIONS (only ConnectionException may escape):\n";
    foreach (array_slice($violations, 0, 20) as $violation) {
        echo sprintf("  iter %d op %s -> %s\n", $violation['iteration'], $violation['operation'], $violation['error']);
    }
    echo sprintf("  ... %d total\n", count($violations));
}
if ($leak) {
    echo "\nPOTENTIAL LEAK: resident memory trended up beyond threshold.\n";
}
if ($connectionLeak) {
    echo "\nSESSION LEAK: live connections exceeded the relay count.\n";
}
if (0 !== $leftConnected) {
    echo "\nTEARDOWN INCOMPLETE: connections remained after close().\n";
}

echo "\nRESULT: ".($ok ? 'PASS' : 'FAIL')."\n";

exit($ok ? 0 : 1);
