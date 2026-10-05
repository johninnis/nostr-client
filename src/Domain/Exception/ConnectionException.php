<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Exception;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Throwable;

final class ConnectionException extends ClientException
{
    public function __construct(
        string $message = '',
        ?Throwable $previous = null,
        private readonly ?RelayUrl $relayUrl = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRelayUrl(): ?RelayUrl
    {
        return $this->relayUrl;
    }

    public static function forRelay(RelayUrl $relayUrl, string $message, ?Throwable $previous = null): self
    {
        return new self(
            message: 'Connection error for relay '.(string) $relayUrl.': '.$message,
            previous: $previous,
            relayUrl: $relayUrl
        );
    }
}
