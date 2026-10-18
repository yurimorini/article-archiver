<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Owner;

/**
 * Proves that an owner keeps the email it is given.
 */
final class OwnerTest extends TestCase
{
    public function test_keeps_the_email(): void
    {
        $owner = new Owner('ada@example.com');

        self::assertSame('ada@example.com', $owner->email);
    }
}
