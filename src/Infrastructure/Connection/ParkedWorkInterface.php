<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

interface ParkedWorkInterface
{
    public function resume(RelaySession $session): void;

    public function refuse(RelaySession $session, string $reason): void;
}
