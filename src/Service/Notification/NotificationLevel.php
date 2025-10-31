<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Notification severity levels
 *
 * Maps to PSR-3 log levels for compatibility with logging systems
 */
enum NotificationLevel: string
{
    /**
     * Informational message
     * Example: Task completed successfully, milestone reached
     */
    case INFO = 'info';

    /**
     * Warning condition
     * Example: Retry attempted, degraded performance
     */
    case WARNING = 'warning';

    /**
     * Error condition
     * Example: Task failed, data validation error
     */
    case ERROR = 'error';

    /**
     * Critical condition requiring immediate attention
     * Example: Circuit breaker opened, service down, data loss
     */
    case CRITICAL = 'critical';

    /**
     * Get PSR-3 compatible log method name
     *
     * @return string
     */
    public function getLogMethod(): string
    {
        return $this->value;
    }
}
