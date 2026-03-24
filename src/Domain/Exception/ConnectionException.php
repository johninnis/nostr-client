<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Domain\Exception;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Throwable;

final class ConnectionException extends ClientException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?RelayUrl $relayUrl = null,
    ) {
        parent::__construct($message, $code, $previous);
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

    public static function timeout(RelayUrl $relayUrl, int $timeoutSeconds): self
    {
        return new self(
            message: 'Connection timeout after '.$timeoutSeconds.'s for relay '.(string) $relayUrl,
            code: 408,
            relayUrl: $relayUrl
        );
    }

    public static function refused(RelayUrl $relayUrl): self
    {
        return new self(
            message: 'Connection refused by relay '.(string) $relayUrl,
            code: 61,
            relayUrl: $relayUrl
        );
    }

    public static function unauthorised(RelayUrl $relayUrl): self
    {
        return new self(
            message: 'Unauthorised connection to relay '.(string) $relayUrl,
            code: 401,
            relayUrl: $relayUrl
        );
    }
}
