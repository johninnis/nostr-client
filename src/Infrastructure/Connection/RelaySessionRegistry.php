<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Amp\DeferredCancellation;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final class RelaySessionRegistry
{
    /** @var array<string, RelaySession> */
    private array $sessions = [];

    // Deliberate: the generation counter is kept here, apart from the session, so a superseded message loop can be fenced across connections - see ADR-0008
    /** @var array<string, int> */
    private array $generations = [];

    // Deliberate: the reconnect cancellation is kept here, apart from the session, because it exists during the reconnect window when there is deliberately no session for the relay - see ADR-0005
    /** @var array<string, DeferredCancellation> */
    private array $reconnectCancellations = [];

    public function find(RelayUrl $relayUrl): ?RelaySession
    {
        return $this->sessions[(string) $relayUrl] ?? null;
    }

    public function store(RelayUrl $relayUrl, RelaySession $session): void
    {
        $this->sessions[(string) $relayUrl] = $session;
    }

    public function remove(RelayUrl $relayUrl): void
    {
        unset($this->sessions[(string) $relayUrl]);
    }

    /**
     * @return list<RelaySession>
     */
    public function all(): array
    {
        return array_values($this->sessions);
    }

    public function nextGeneration(RelayUrl $relayUrl): int
    {
        $generation = $this->generation($relayUrl) + 1;
        $this->generations[(string) $relayUrl] = $generation;

        return $generation;
    }

    public function generation(RelayUrl $relayUrl): int
    {
        return $this->generations[(string) $relayUrl] ?? 0;
    }

    public function hasReconnect(RelayUrl $relayUrl): bool
    {
        return isset($this->reconnectCancellations[(string) $relayUrl]);
    }

    public function beginReconnect(RelayUrl $relayUrl, DeferredCancellation $cancellation): void
    {
        $this->reconnectCancellations[(string) $relayUrl] = $cancellation;
    }

    public function endReconnect(RelayUrl $relayUrl): void
    {
        unset($this->reconnectCancellations[(string) $relayUrl]);
    }

    public function cancelReconnect(RelayUrl $relayUrl): void
    {
        $cancellation = $this->reconnectCancellations[(string) $relayUrl] ?? null;
        if (null === $cancellation) {
            return;
        }

        $cancellation->cancel();
        unset($this->reconnectCancellations[(string) $relayUrl]);
    }
}
