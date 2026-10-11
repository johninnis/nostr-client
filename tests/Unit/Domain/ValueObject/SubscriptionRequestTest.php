<?php

declare(strict_types=1);

namespace Innis\Nostr\Client\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Client\Domain\ValueObject\SubscriptionRequest;
use Innis\Nostr\Core\Domain\Collection\FilterCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubscriptionRequestTest extends TestCase
{
    public function testARequestNamingTheKeepAliveIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionRequest(self::relay(), new FilterCollection([Filter::from()]), self::subscriptionId('keepalive'));
    }

    public function testARequestForOneFilterNamingTheKeepAliveIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SubscriptionRequest::for(self::relay(), Filter::from(), self::subscriptionId('keepalive'));
    }

    public function testGivingARequestTheKeepAliveIdIsRefused(): void
    {
        $request = SubscriptionRequest::for(self::relay(), Filter::from());

        $this->expectException(InvalidArgumentException::class);

        $request->withSubscriptionId(self::subscriptionId('keepalive'));
    }

    public function testARequestKeepsAnIdItNames(): void
    {
        $request = SubscriptionRequest::for(self::relay(), Filter::from(), self::subscriptionId('feed'));

        self::assertSame('feed', (string) $request->getSubscriptionId());
    }

    public function testARequestMayNameAnIdThatDiffersFromTheKeepAliveIdOnlyInCase(): void
    {
        $request = SubscriptionRequest::for(self::relay(), Filter::from(), self::subscriptionId('KeepAlive'));

        self::assertSame('KeepAlive', (string) $request->getSubscriptionId());
    }

    private static function relay(): RelayUrl
    {
        return RelayUrl::tryFromString('wss://relay.test') ?? self::fail('invalid relay URL');
    }

    private static function subscriptionId(string $value): SubscriptionId
    {
        return SubscriptionId::tryFromString($value) ?? self::fail('invalid subscription id');
    }
}
