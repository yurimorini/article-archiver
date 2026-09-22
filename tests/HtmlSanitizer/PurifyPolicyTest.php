<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\HtmlSanitizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\HtmlSanitizer\PurifyPolicy;

final class PurifyPolicyTest extends TestCase
{
    public function test_defaults_are_the_mvp_allowlist_and_debug_off(): void
    {
        $policy = new PurifyPolicy();

        self::assertSame(['http', 'https', 'mailto'], $policy->allowedSchemes);
        self::assertFalse($policy->debug);
    }

    public function test_subset_and_reordered_allowlist_is_stored_as_given(): void
    {
        $policy = new PurifyPolicy(['mailto', 'https'], true);

        self::assertSame(['mailto', 'https'], $policy->allowedSchemes);
        self::assertTrue($policy->debug);
    }

    #[DataProvider('rejectedSchemes')]
    public function test_rejects_schemes_outside_the_mvp_allowlist(array $schemes, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PurifyPolicy($schemes);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function rejectedSchemes(): array
    {
        return [
            'empty list' => [[], 'allowedSchemes must not be empty'],
            'javascript' => [['javascript'], 'allowedSchemes only allows http, https, and mailto'],
            'data' => [['data'], 'allowedSchemes only allows http, https, and mailto'],
            'file' => [['file'], 'allowedSchemes only allows http, https, and mailto'],
            'ftp' => [['ftp'], 'allowedSchemes only allows http, https, and mailto'],
            'allowed plus ftp' => [['http', 'ftp'], 'allowedSchemes only allows http, https, and mailto'],
            'uppercase' => [['HTTP'], 'allowedSchemes must be lowercase scheme names'],
            'with colon' => [['http:'], 'allowedSchemes must be lowercase scheme names'],
            'blank token' => [[''], 'allowedSchemes must be lowercase scheme names'],
            'embedded space' => [['http '], 'allowedSchemes must be lowercase scheme names'],
        ];
    }
}
