<?php

namespace App\Service;

/**
 * Exception thrown when circuit breaker is in open state
 */
class CircuitBreakerException extends \RuntimeException
{
    public function __construct(string $message = 'Circuit breaker is open', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
