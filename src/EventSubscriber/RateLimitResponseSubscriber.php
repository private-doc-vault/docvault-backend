<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rate Limit Response Header Subscriber
 *
 * Adds rate limiting headers to all API responses
 * when rate limiting information is available
 */
class RateLimitResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        // Only add headers to API responses
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Check if rate limit information was set by RateLimitSubscriber
        $rateLimit = $request->attributes->get('_rate_limit');

        if ($rateLimit && is_array($rateLimit)) {
            $response->headers->set('X-Rate-Limit-Limit', (string)$rateLimit['limit']);
            $response->headers->set('X-Rate-Limit-Remaining', (string)$rateLimit['remaining']);
            $response->headers->set('X-Rate-Limit-Reset', (string)$rateLimit['reset_time']);
            $response->headers->set('X-Rate-Limit-Used', (string)$rateLimit['used']);
        }
    }
}