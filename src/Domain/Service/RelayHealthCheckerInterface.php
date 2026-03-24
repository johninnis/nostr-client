<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Service;

use Innis\Nostr\Client\Domain\ValueObject\HealthCheckResult;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

interface RelayHealthCheckerInterface
{
    public function checkHealth(RelayUrl $relayUrl, float $timeout = 5.0): HealthCheckResult;
}
