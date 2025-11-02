<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Idempotency Service
 *
 * Prevents duplicate processing of requests by tracking idempotency tokens
 * Tokens are stored in Redis/cache with TTL to prevent memory bloat
 */
class IdempotencyService
{
    private const TOKEN_PREFIX = 'idempotency_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 3600 // Default 1 hour
    ) {
    }

    /**
     * Generate a unique idempotency token
     *
     * @return string
     */
    public function generateToken(): string
    {
        return md5(uniqid('', true) . random_bytes(16));
    }

    /**
     * Generate idempotency token from context data
     *
     * Same context will always produce the same token
     *
     * @param array $context
     * @return string
     */
    public function generateTokenFromContext(array $context): string
    {
        ksort($context); // Ensure consistent ordering
        return md5(json_encode($context));
    }

    /**
     * Check if a token has been used
     *
     * @param string $token
     * @return bool
     */
    public function wasTokenUsed(string $token): bool
    {
        $cacheKey = self::TOKEN_PREFIX . $token;

        try {
            $value = $this->cache->get($cacheKey, function (ItemInterface $item) {
                // Token not found, return null
                return null;
            });

            return $value !== null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check idempotency token', [
                'token' => $token,
                'error' => $e->getMessage()
            ]);
            // If cache fails, assume token was not used to allow processing
            return false;
        }
    }

    /**
     * Mark a token as used
     *
     * @param string $token
     * @return void
     */
    public function markTokenAsUsed(string $token): void
    {
        $cacheKey = self::TOKEN_PREFIX . $token;

        try {
            $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter($this->ttl);
                return true;
            });

            $this->logger->debug('Idempotency token marked as used', [
                'token' => substr($token, 0, 8) . '...', // Log only prefix for security
                'ttl' => $this->ttl
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark idempotency token as used', [
                'token' => substr($token, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process a callable with idempotency protection
     *
     * If token was already used, returns null without executing callable
     * Otherwise, executes callable, marks token as used, and returns result
     *
     * @template T
     * @param string $token
     * @param callable(): T $callable
     * @return T|null
     */
    public function processWithIdempotency(string $token, callable $callable): mixed
    {
        // Check if token was already used
        if ($this->wasTokenUsed($token)) {
            $this->logger->info('Idempotent request detected - skipping execution', [
                'token' => substr($token, 0, 8) . '...'
            ]);
            return null;
        }

        // Execute callable
        $result = $callable();

        // Mark token as used
        $this->markTokenAsUsed($token);

        return $result;
    }
}
