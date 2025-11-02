<?php

namespace App\Service\ErrorCategorization;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use App\Service\CircuitBreakerException;

/**
 * Error Categorization Service
 *
 * Categorizes exceptions as transient (retriable) or permanent (non-retriable)
 * to optimize retry logic and prevent unnecessary retry attempts
 */
class ErrorCategorizer
{
    /**
     * Categorize an exception as transient or permanent
     *
     * @param \Throwable $exception
     * @return ErrorCategory
     */
    public function categorize(\Throwable $exception): ErrorCategory
    {
        // Network and transport errors are typically transient
        if ($exception instanceof TransportExceptionInterface) {
            return ErrorCategory::TRANSIENT;
        }

        // Server errors (5xx) are transient - service may recover
        if ($exception instanceof ServerExceptionInterface) {
            return ErrorCategory::TRANSIENT;
        }

        // Circuit breaker is open - transient (will recover after timeout)
        if ($exception instanceof CircuitBreakerException) {
            return ErrorCategory::TRANSIENT;
        }

        // Client errors (4xx) are permanent - bad request won't succeed on retry
        if ($exception instanceof ClientExceptionInterface) {
            return ErrorCategory::PERMANENT;
        }

        // Check exception message for common transient error patterns
        $message = strtolower($exception->getMessage());

        $transientPatterns = [
            'timeout',
            'timed out',
            'connection refused',
            'connection reset',
            'temporarily unavailable',
            'service unavailable',
            'too many requests',
            'rate limit',
            'deadlock',
            'lock wait timeout',
        ];

        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return ErrorCategory::TRANSIENT;
            }
        }

        $permanentPatterns = [
            'not found',
            'file not found',
            'invalid',
            'forbidden',
            'unauthorized',
            'authentication failed',
            'permission denied',
            'access denied',
            'bad request',
        ];

        foreach ($permanentPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return ErrorCategory::PERMANENT;
            }
        }

        // Default to permanent to avoid infinite retries on unknown errors
        return ErrorCategory::PERMANENT;
    }

    /**
     * Check if an exception should be retried
     *
     * @param \Throwable $exception
     * @return bool
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        return $this->categorize($exception)->isTransient();
    }

    /**
     * Get a human-readable description of the error category
     *
     * @param \Throwable $exception
     * @return string
     */
    public function getDescription(\Throwable $exception): string
    {
        $category = $this->categorize($exception);
        $exceptionType = get_class($exception);

        if ($category->isTransient()) {
            return "Transient error ($exceptionType): " . $exception->getMessage() . " - Will retry";
        }

        return "Permanent error ($exceptionType): " . $exception->getMessage() . " - Will not retry";
    }
}
