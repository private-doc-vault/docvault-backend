<?php

namespace App\Service\ErrorCategorization;

/**
 * Error category enumeration
 *
 * Defines whether an error should be retried or is permanent
 */
enum ErrorCategory: string
{
    /**
     * Transient errors that should be retried
     * Examples: Network timeout, temporary service unavailability, rate limits
     */
    case TRANSIENT = 'transient';

    /**
     * Permanent errors that should not be retried
     * Examples: Invalid input, authentication failure, resource not found
     */
    case PERMANENT = 'permanent';

    public function isTransient(): bool
    {
        return $this === self::TRANSIENT;
    }

    public function isPermanent(): bool
    {
        return $this === self::PERMANENT;
    }
}
