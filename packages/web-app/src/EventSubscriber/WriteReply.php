<?php

declare(strict_types=1);

namespace Yumo\Eleanor\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Yumo\Eleanor\Api\Response\Reply;
use Yumo\Eleanor\Api\Response\ReplyResponder;

/**
 * Turns the `Reply` an action returns into the HTTP response.
 */
final class WriteReply implements EventSubscriberInterface
{
    public function __construct(private readonly ReplyResponder $responder)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => 'onView',
        ];
    }

    /**
     * Sets the response when the controller result is a reply. Any other result is left alone.
     */
    public function onView(ViewEvent $event): void
    {
        $result = $event->getControllerResult();

        if (!$result instanceof Reply) {
            return;
        }

        $event->setResponse($this->responder->respond($result));
    }
}
