<?php

declare(strict_types=1);

namespace Yumo\Eleanor\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Yumo\Eleanor\Request\EnrichedRequest;
use Yumo\Eleanor\Request\RequestEnricher;

/**
 * Writes the API request start and finish lines and stores the enriched request.
 */
#[WithMonologChannel('application')]
final class LogApiRequest implements EventSubscriberInterface
{
    private const string STARTED_AT = 'eleanor.http_request_started_at';

    public function __construct(
        private readonly RequestEnricher $enricher,
        private readonly LoggerInterface $application,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    /**
     * Stores the enriched request, then writes http.request.start.
     */
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $class = $this->controllerClass($event->getController());

        // The payload slice moved the controller to Yumo\Eleanor\Api\Controller.
        if ($class === null || !str_starts_with($class, 'Yumo\\Eleanor\\Api\\Controller\\')) {
            return;
        }

        $request = $event->getRequest();
        $enriched = $this->enricher->enrich($request);
        $request->attributes->set(EnrichedRequest::class, $enriched);
        $request->attributes->set(self::STARTED_AT, hrtime(true));

        try {
            $this->application->info('http.request.start', $this->startContext($request, $enriched));
        } catch (\Throwable) {
            // A failed write must not change the action's response.
        }
    }

    /**
     * Sets X-Request-Id, then writes http.request.finish.
     */
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $enriched = $request->attributes->get(EnrichedRequest::class);

        if (!$enriched instanceof EnrichedRequest) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('X-Request-Id', $enriched->requestId);

        $startedAt = $request->attributes->get(self::STARTED_AT);

        if (!is_int($startedAt)) {
            return;
        }

        $now = hrtime(true);

        if (!is_int($now)) {
            return;
        }

        try {
            $this->application->info('http.request.finish', [
                'request_id' => $enriched->requestId,
                'status' => $response->getStatusCode(),
                'duration_ms' => intdiv($now - $startedAt, 1_000_000),
            ]);
        } catch (\Throwable) {
            // A failed write must not change the action's response.
        }
    }

    private function controllerClass(mixed $controller): ?string
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }

        if (!is_object($controller)) {
            return null;
        }

        return $controller::class;
    }

    /**
     * @return array<string, int|string>
     */
    private function startContext(Request $request, EnrichedRequest $enriched): array
    {
        $route = $request->attributes->get('_route');
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        if (is_string($query) && $query !== '') {
            $path .= '?'.$query;
        }

        $context = [
            'method' => $request->getMethod(),
            'route' => is_string($route) ? $route : '',
            'path' => $path,
            'request_id' => $enriched->requestId,
        ];

        if ($enriched->userAgent !== null) {
            $context['user_agent'] = $enriched->userAgent;
        }

        if ($enriched->referer !== null) {
            $context['referer'] = $enriched->referer;
        }

        return $context;
    }
}
