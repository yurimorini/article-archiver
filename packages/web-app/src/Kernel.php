<?php

declare(strict_types=1);

namespace Yumo\Eleanor;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;

/**
 * Boots Eleanor in prod, dev, or test, and reports that environment.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Turns off the framework's trailing-slash redirect.
     *
     * `GET /api/archives/` would otherwise redirect onto the list, and `GET /api/archives/17.json`
     * would redirect onto a slash form that the detail requirement accepts. Both stay `not_found`.
     */
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new class () implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $definition = $container->getDefinition('router.default');
                $options = $definition->getArgument(2);

                if (!is_array($options)) {
                    throw new \LogicException('The router options are not an array.');
                }

                $options['matcher_class'] = CompiledUrlMatcher::class;
                $definition->setArgument(2, $options);
            }
        });
    }

    /**
     * Boots the kernel, then resolves the framework secret.
     *
     * An empty or missing secret fails here, while `kernel.secret` is resolved.
     */
    public function boot(): void
    {
        parent::boot();

        $this->getContainer()->getParameter('kernel.secret');
    }

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
