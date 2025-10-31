<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Notification Service Interface
 *
 * Provides an extensible notification system for critical failures and alerts.
 * Implementations can send notifications via various channels:
 * - Logging (default)
 * - Email
 * - Slack
 * - PagerDuty
 * - Custom integrations
 */
interface NotificationServiceInterface
{
    /**
     * Send a notification
     *
     * @param string $message The notification message
     * @param NotificationLevel $level The severity level
     * @param array<string, mixed> $context Additional context data
     * @return void
     */
    public function notify(
        string $message,
        NotificationLevel $level = NotificationLevel::INFO,
        array $context = []
    ): void;
}
