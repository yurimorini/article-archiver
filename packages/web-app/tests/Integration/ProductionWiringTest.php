<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Yumo\ArtcleReader\Orchestrator\OrchestratorError;
use Yumo\ArtcleReader\Orchestrator\OrchestratorException;
use Yumo\Eleanor\Archive\ArchiveException;
use Yumo\Eleanor\Kernel;
use Yumo\Eleanor\Tests\Support\Bearer;

/**
 * Proves that the production container reaches the real library orchestrator.
 *
 * The test environment replaces the fetcher with a fake, so only this test reads the production service definitions.
 * Debug mode keeps the cache fresh when service files change. A string that is not a URL is refused by the library before any network call.
 */
final class ProductionWiringTest extends TestCase
{
    public function test_production_container_reaches_the_library_url_check(): void
    {
        $kernel = new Kernel('prod', true);
        $request = Request::create(
            '/api/archives',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => Bearer::header()],
            content: '{"url":"not-a-url"}',
        );

        try {
            $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            self::fail('Expected an ArchiveException.');
        } catch (ArchiveException $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(OrchestratorException::class, $cause);
            self::assertSame(OrchestratorError::UrlGuard, $cause->error);
        } finally {
            $kernel->shutdown();
        }
    }
}
