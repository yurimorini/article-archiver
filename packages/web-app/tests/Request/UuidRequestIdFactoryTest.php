<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Request\UuidRequestIdFactory;

/**
 * Proves which header values stay and which become a UUID version 4.
 */
final class UuidRequestIdFactoryTest extends TestCase
{
    public function test_keeps_one_character_and_128_characters(): void
    {
        $factory = new UuidRequestIdFactory();
        $long = str_repeat('A1._-', 25) . 'b9Z';

        self::assertSame(128, strlen($long));
        self::assertSame('.', $factory->fromHeader('.'));
        self::assertSame($long, $factory->fromHeader($long));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'padded' => [' a '];
        yield 'newline' => ["\n"];
        yield 'non-ascii' => ['é'];
        yield 'length 129' => [str_repeat('A1._-', 25) . 'b9Za'];
    }

    #[DataProvider('rejectedProvider')]
    public function test_replaces_values_outside_the_rule(?string $value): void
    {
        $id = new UuidRequestIdFactory()->fromHeader($value);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
        self::assertNotSame($value, $id);
    }
}
