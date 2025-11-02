<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exception Subscriber for converting exceptions to JSON responses on API routes
 */
class ExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Only handle API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        // Handle Access Denied exceptions
        if ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
            $response = new JsonResponse([
                'error' => 'Access denied',
                'message' => $exception->getMessage() ?: 'You do not have permission to access this resource'
            ], Response::HTTP_FORBIDDEN);

            $event->setResponse($response);
            return;
        }

        // Handle other HTTP exceptions
        if ($exception instanceof HttpExceptionInterface) {
            $response = new JsonResponse([
                'error' => $exception->getMessage() ?: 'An error occurred',
                'code' => $exception->getStatusCode()
            ], $exception->getStatusCode());

            $event->setResponse($response);
            return;
        }

        // Handle generic exceptions (only in dev/test environment for security)
        if ($_ENV['APP_ENV'] !== 'prod') {
            $response = new JsonResponse([
                'error' => 'Internal server error',
                'message' => $exception->getMessage(),
                'code' => Response::HTTP_INTERNAL_SERVER_ERROR
            ], Response::HTTP_INTERNAL_SERVER_ERROR);

            $event->setResponse($response);
        }
    }
}
