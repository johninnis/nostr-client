<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Support;

use Innis\Nostr\Client\Application\Port\AuthResultListenerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Override;

final class RecordingAuthResultListener implements AuthResultListenerInterface
{
    /** @var list<array{relay: string, accepted: bool, message: string}> */
    public array $results = [];

    #[Override]
    public function onAuthResult(RelayUrl $relayUrl, bool $accepted, string $message): void
    {
        $this->results[] = ['relay' => (string) $relayUrl, 'accepted' => $accepted, 'message' => $message];
    }
}
