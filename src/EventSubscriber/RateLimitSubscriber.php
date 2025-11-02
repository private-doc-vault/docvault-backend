<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rate Limiting Event Subscriber
 *
 * Provides comprehensive rate limiting for API endpoints with:
 * - IP-based rate limiting for anonymous users
 * - User-based rate limiting for authenticated users
 * - Different limits per endpoint type
 * - Admin bypass functionality
 * - Proper HTTP headers and error responses
 */
class RateLimitSubscriber implements EventSubscriberInterface
{
    private const CACHE_PREFIX = 'rate_limit_';

    // Rate limits per minute
    private const LIMITS = [
        'anonymous' => [
            'default' => 5,
            'auth' => 3,      // Login/register endpoints
            'public' => 10    // Public endpoints like health checks
        ],
        'authenticated' => [
            'default' => 20,
            'auth' => 10,     // Password changes, etc.
            'profile' => 30   // Profile operations
        ],
        'admin' => [
            'default' => 1000  // Effectively unlimited for admins
        ]
    ];

    private const BURST_ALLOWANCE = [
        'anonymous' => 3,      // Allow 3 extra requests in quick succession
        'authenticated' => 5,  // Allow 5 extra requests for authenticated users
        'admin' => 50         // High burst for admins
    ];

    public function __construct(
        private CacheItemPoolInterface $cache,
        private TokenStorageInterface $tokenStorage,
        private ParameterBagInterface $parameterBag
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5], // Lower priority, after authentication
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Skip rate limiting in test environment to avoid interfering with tests
        if ($this->parameterBag->get('kernel.environment') === 'test') {
            return;
        }

        // Only apply rate limiting to API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Exclude webhook endpoints from rate limiting (they use HMAC authentication)
        if (str_starts_with($request->getPathInfo(), '/api/webhooks/')) {
            return;
        }

        $userType = $this->getUserType();
        $endpointType = $this->getEndpointType($request->getPathInfo());
        $identifier = $this->getIdentifier($request, $userType);

        $rateLimit = $this->checkRateLimit($identifier, $userType, $endpointType);

        // Add rate limit headers to the response
        $this->addRateLimitHeaders($event, $rateLimit);

        if ($rateLimit['exceeded']) {
            $response = new JsonResponse([
                'error' => 'Rate limit exceeded. Too many requests.',
                'retry_after' => $rateLimit['reset_time'] - time()
            ], Response::HTTP_TOO_MANY_REQUESTS);

            $this->addRateLimitHeadersToResponse($response, $rateLimit);
            $event->setResponse($response);
        }
    }

    private function getUserType(): string
    {
        $token = $this->tokenStorage->getToken();

        if (!$token || !$token->getUser() instanceof UserInterface) {
            return 'anonymous';
        }

        $user = $token->getUser();

        // Check if user has admin role
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return 'admin';
        }

        return 'authenticated';
    }

    private function getEndpointType(string $path): string
    {
        if (str_contains($path, '/auth/')) {
            return 'auth';
        }

        if (str_contains($path, '/profile')) {
            return 'profile';
        }

        if (in_array($path, ['/api/health', '/api/status'])) {
            return 'public';
        }

        return 'default';
    }

    private function getIdentifier($request, string $userType): string
    {
        if ($userType === 'authenticated' || $userType === 'admin') {
            $token = $this->tokenStorage->getToken();
            if ($token && $token->getUser() instanceof UserInterface) {
                $user = $token->getUser();
                if (method_exists($user, 'getId')) {
                    return 'user:' . $user->getId();
                }
                return 'user:' . $user->getUserIdentifier();
            }
        }

        // For anonymous users, use IP address
        $ip = $request->getClientIp();

        // Check for forwarded IP (for load balancers/proxies)
        if ($request->headers->has('X-Forwarded-For')) {
            $forwardedIps = explode(',', $request->headers->get('X-Forwarded-For'));
            $ip = trim($forwardedIps[0]);
        }

        return 'ip:' . $ip;
    }

    private function checkRateLimit(string $identifier, string $userType, string $endpointType): array
    {
        $limit = $this->getLimit($userType, $endpointType);
        $burstAllowance = self::BURST_ALLOWANCE[$userType] ?? 0;
        $windowDuration = 60; // 1 minute in seconds

        $now = time();
        $windowStart = $now - $windowDuration;

        // Create cache key (sanitize for cache compatibility)
        $sanitizedIdentifier = $this->sanitizeCacheKey($identifier);
        $cacheKey = self::CACHE_PREFIX . $sanitizedIdentifier . '_' . $endpointType;
        $burstCacheKey = self::CACHE_PREFIX . $sanitizedIdentifier . '_burst_' . $endpointType;

        try {
            // Get current request count
            $requestsItem = $this->cache->getItem($cacheKey);
            $requests = $requestsItem->isHit() ? $requestsItem->get() : [];

            // Clean old requests outside the window
            $requests = array_filter($requests, function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            });

            // Get burst count
            $burstItem = $this->cache->getItem($burstCacheKey);
            $burstCount = $burstItem->isHit() ? $burstItem->get() : 0;

            // Check if limit exceeded
            $requestCount = count($requests);
            $totalAllowed = $limit + $burstAllowance;

            if ($requestCount >= $totalAllowed) {
                return [
                    'exceeded' => true,
                    'limit' => $limit,
                    'remaining' => 0,
                    'reset_time' => $windowStart + $windowDuration + 1,
                    'used' => $requestCount
                ];
            }

            // Add current request
            $requests[] = $now;

            // Update cache
            $requestsItem->set($requests);
            $requestsItem->expiresAfter($windowDuration + 5); // Small buffer
            $this->cache->save($requestsItem);

            // Update burst count if using burst allowance
            if ($requestCount > $limit) {
                $burstCount = $requestCount - $limit;
                $burstItem->set($burstCount);
                $burstItem->expiresAfter(10); // Burst allowance resets quickly
                $this->cache->save($burstItem);
            }

            return [
                'exceeded' => false,
                'limit' => $limit,
                'remaining' => max(0, $totalAllowed - count($requests)),
                'reset_time' => $windowStart + $windowDuration + 1,
                'used' => count($requests)
            ];

        } catch (\Exception $e) {
            // If cache fails, allow the request but log the error
            error_log('Rate limit cache error: ' . $e->getMessage());

            return [
                'exceeded' => false,
                'limit' => $limit,
                'remaining' => $limit - 1,
                'reset_time' => $now + $windowDuration,
                'used' => 1
            ];
        }
    }

    private function getLimit(string $userType, string $endpointType): int
    {
        $userLimits = self::LIMITS[$userType] ?? self::LIMITS['anonymous'];
        return $userLimits[$endpointType] ?? $userLimits['default'];
    }

    private function addRateLimitHeaders(RequestEvent $event, array $rateLimit): void
    {
        // Headers will be added to response when it's created
        $event->getRequest()->attributes->set('_rate_limit', $rateLimit);
    }

    private function addRateLimitHeadersToResponse(Response $response, array $rateLimit): void
    {
        $response->headers->set('X-Rate-Limit-Limit', (string)$rateLimit['limit']);
        $response->headers->set('X-Rate-Limit-Remaining', (string)$rateLimit['remaining']);
        $response->headers->set('X-Rate-Limit-Reset', (string)$rateLimit['reset_time']);
        $response->headers->set('X-Rate-Limit-Used', (string)$rateLimit['used']);

        if ($rateLimit['exceeded']) {
            $response->headers->set('Retry-After', (string)($rateLimit['reset_time'] - time()));
        }
    }

    private function sanitizeCacheKey(string $key): string
    {
        // Replace characters that aren't allowed in cache keys with safe alternatives
        // PSR-6 compliant keys: A-Z, a-z, 0-9, _, .
        return preg_replace('/[^A-Za-z0-9._]/', '', $key);
    }
}