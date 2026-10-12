<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Request as BrowserRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel browser that keeps a missing User-Agent missing.
 */
final class LoggingBrowser extends KernelBrowser
{
    /**
     * @param array<string, mixed> $server
     */
    public function setServerParameters(array $server): void
    {
        $sentUserAgent = array_key_exists('HTTP_USER_AGENT', $server);

        parent::setServerParameters($server);

        if (!$sentUserAgent) {
            unset($this->server['HTTP_USER_AGENT']);
        }
    }

    protected function filterRequest(BrowserRequest $request): Request
    {
        $server = $request->getServer();
        $httpRequest = parent::filterRequest($request);

        if (!array_key_exists('HTTP_USER_AGENT', $server)) {
            $httpRequest->headers->remove('User-Agent');
        }

        return $httpRequest;
    }
}
