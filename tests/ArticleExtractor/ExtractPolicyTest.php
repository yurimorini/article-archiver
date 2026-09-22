<?php

declare(strict_types=1);

namespace Yumo\LogRead\Tests\ArticleExtractor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yumo\LogRead\ArticleExtractor\ExtractPolicy;

final class ExtractPolicyTest extends TestCase
{
    public function test_defaults_match_the_project_policy(): void
    {
        $policy = new ExtractPolicy();

        self::assertFalse($policy->debug);
        self::assertTrue($policy->fixRelativeURLs);
        self::assertSame(500, $policy->charThreshold);
        self::assertSame(30000, $policy->maxElemsToParse);
    }

    public function test_zero_limits_are_explicit_opt_outs(): void
    {
        $policy = new ExtractPolicy(charThreshold: 0, maxElemsToParse: 0);

        self::assertSame(0, $policy->charThreshold);
        self::assertSame(0, $policy->maxElemsToParse);
    }

    #[DataProvider('invalidConstructorArgs')]
    public function test_rejects_negative_limits(callable $build, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $build();
    }

    /**
     * @return array<string, array{callable(): ExtractPolicy, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'negative char threshold' => [
                static fn (): ExtractPolicy => new ExtractPolicy(charThreshold: -1),
                'charThreshold must not be negative',
            ],
            'negative element cap' => [
                static fn (): ExtractPolicy => new ExtractPolicy(maxElemsToParse: -1),
                'maxElemsToParse must not be negative',
            ],
        ];
    }
}
