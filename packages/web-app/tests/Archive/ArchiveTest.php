<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Archive;

use PHPUnit\Framework\TestCase;
use Yumo\Eleanor\Archive\Archive;

/**
 * Proves that an archive keeps its database identity.
 */
final class ArchiveTest extends TestCase
{
    public function test_keeps_null_and_an_assigned_id(): void
    {
        $unsaved = new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>', null);
        $saved = new Archive('https://example.com/a', 'Title', null, null, '<p>Body</p>', 17);

        self::assertNull($unsaved->id);
        self::assertSame(17, $saved->id);
    }
}
