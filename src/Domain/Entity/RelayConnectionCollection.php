<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Override;

/**
 * @extends TypedCollection<RelayConnection>
 */
final class RelayConnectionCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return RelayConnection::class;
    }

    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->items, $predicate));
    }
}
