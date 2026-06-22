<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<HealthCheckResult>
 */
final class HealthCheckResultCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return HealthCheckResult::class;
    }
}
