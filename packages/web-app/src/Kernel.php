<?php

declare(strict_types=1);

namespace Yumo\Eleanor;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Boots Eleanor in prod, dev, or test, and reports that environment.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     *
     * @phpstan-ignore method.unused
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
