<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Owner;

/**
 * Proves that an owner normalizes the email it is given.
 */
final class OwnerTest extends TestCase
{
    public function test_lowercases_the_whole_address_and_keeps_plus_and_dots(): void
    {
        $owner = new Owner('Ada+Box@Example.com');

        self::assertSame('ada+box@example.com', $owner->email);
    }

    public function test_keeps_an_ascii_address(): void
    {
        $owner = new Owner('ada@example.com');

        self::assertSame('ada@example.com', $owner->email);
    }
}
