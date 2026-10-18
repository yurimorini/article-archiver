<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Api\Owner;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Api\Owner\ConfiguredOwnerProvider;

/**
 * Proves that the configured provider answers with the email it is given.
 */
final class ConfiguredOwnerProviderTest extends TestCase
{
    public function test_returns_an_owner_with_the_configured_email(): void
    {
        $provider = new ConfiguredOwnerProvider('ada@example.com');

        self::assertSame('ada@example.com', $provider->owner()->email);
    }
}
