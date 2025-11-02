<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Circuit Breaker pattern implementation to prevent cascading failures
 *
 * States:
 * - CLOSED: Normal operation, all calls pass through
 * - OPEN: Too many failures detected, calls fail immediately
 * - HALF_OPEN: Testing if service recovered, one call allowed
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private ?int $openedAt = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $failureThreshold = 5,
        private readonly int $resetTimeout = 60 // seconds
    ) {
    }

    /**
     * Execute a callable with circuit breaker protection
     *
     * @template T
     * @param callable(): T $callable
     * @return T
     * @throws CircuitBreakerException When circuit is open
     * @throws \Throwable When the callable fails
     */
    public function call(callable $callable): mixed
    {
        $this->checkState();

        if ($this->state === self::STATE_OPEN) {
            throw new CircuitBreakerException('Circuit breaker is open');
        }

        try {
            $result = $callable();
            $this->onSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure();
            throw $e;
        }
    }

    public function isClosed(): bool
    {
        $this->checkState();
        return $this->state === self::STATE_CLOSED;
    }

    public function isOpen(): bool
    {
        $this->checkState();
        return $this->state === self::STATE_OPEN;
    }

    public function isHalfOpen(): bool
    {
        $this->checkState();
        return $this->state === self::STATE_HALF_OPEN;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->openedAt = null;

        $this->logger->info('Circuit breaker manually reset', [
            'state' => $this->state,
        ]);
    }

    private function checkState(): void
    {
        if ($this->state === self::STATE_OPEN && $this->shouldAttemptReset()) {
            $this->state = self::STATE_HALF_OPEN;
            $this->logger->info('Circuit breaker transitioned to half-open state', [
                'opened_at' => $this->openedAt,
                'reset_timeout' => $this->resetTimeout,
            ]);
        }
    }

    private function shouldAttemptReset(): bool
    {
        if ($this->openedAt === null) {
            return false;
        }

        return (time() - $this->openedAt) >= $this->resetTimeout;
    }

    private function onSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            $this->logger->info('Circuit breaker closed after successful half-open call', [
                'previous_failure_count' => $this->failureCount,
            ]);
        }

        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->openedAt = null;
    }

    private function onFailure(): void
    {
        $this->failureCount++;

        if ($this->state === self::STATE_HALF_OPEN) {
            // Any failure in half-open state reopens the circuit
            $this->state = self::STATE_OPEN;
            $this->openedAt = time();

            $this->logger->warning('Circuit breaker reopened after half-open failure', [
                'failure_count' => $this->failureCount,
            ]);
        } elseif ($this->failureCount >= $this->failureThreshold) {
            // Too many failures, open the circuit
            $this->state = self::STATE_OPEN;
            $this->openedAt = time();

            $this->logger->warning('Circuit breaker opened due to failure threshold', [
                'failure_count' => $this->failureCount,
                'threshold' => $this->failureThreshold,
            ]);
        } else {
            $this->logger->debug('Circuit breaker recorded failure', [
                'failure_count' => $this->failureCount,
                'threshold' => $this->failureThreshold,
            ]);
        }
    }
}
