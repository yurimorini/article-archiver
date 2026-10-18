<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Support;

/**
 * Gives a test its own data and lock directories and points the kernel at them.
 *
 * `$root = ArchiveDirectories::isolate();`
 */
final class ArchiveDirectories
{
    /**
     * Creates a temporary root with `data/` and `lock/`, and sets `ELEANOR_DATA_DIR` and `ELEANOR_LOCK_DIR`.
     *
     * The root is removed when the test process ends. Call it before anything boots the kernel.
     *
     * @return string the temporary root
     */
    public static function isolate(): string
    {
        $root = sys_get_temp_dir() . '/eleanor-test-' . bin2hex(random_bytes(6));
        mkdir($root . '/data', 0o777, true);
        mkdir($root . '/lock', 0o777, true);
        register_shutdown_function(static fn () => self::remove($root));

        self::set('ELEANOR_DATA_DIR', $root . '/data');
        self::set('ELEANOR_LOCK_DIR', $root . '/lock');

        return $root;
    }

    /**
     * Removes `$directory` and everything below it.
     */
    public static function remove(string $directory): void
    {
        exec('rm -rf ' . escapeshellarg($directory));
    }

    private static function set(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }
}
