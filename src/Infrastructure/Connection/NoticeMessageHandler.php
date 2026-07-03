<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Infrastructure\Connection;

use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Client\CloseMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Message\Relay\NoticeMessage;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class NoticeMessageHandler
{
    private const string APPLICATION_PING_NOTICE = 'ping';
    private const string KEEP_ALIVE_SUBSCRIPTION_ID = 'keepalive';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(NoticeMessage $message, RelaySession $session): void
    {
        $notice = $message->getMessage();
        $relayUrl = $session->getConnection()->getRelayUrl();

        $this->logger->info('NOTICE message received from relay', [
            'relay' => (string) $relayUrl,
            'notice' => $notice,
        ]);

        if (self::APPLICATION_PING_NOTICE === strtolower(trim($notice))) {
            $this->respondToApplicationPing($session);

            return;
        }

        foreach ($session->distinctHandlers() as $handler) {
            $handler->handleNotice($relayUrl, $notice);
        }
    }

    // Deliberate: keep-alive reply via CLOSE for a throwaway subscription - see ADR-0003
    private function respondToApplicationPing(RelaySession $session): void
    {
        $keepAliveSubscriptionId = SubscriptionId::fromString(self::KEEP_ALIVE_SUBSCRIPTION_ID);

        if (null === $keepAliveSubscriptionId) {
            return;
        }

        try {
            $session->send(new CloseMessage($keepAliveSubscriptionId));

            $this->logger->debug('Responded to application-level ping', [
                'relay' => (string) $session->getConnection()->getRelayUrl(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to respond to application-level ping', [
                'relay' => (string) $session->getConnection()->getRelayUrl(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
