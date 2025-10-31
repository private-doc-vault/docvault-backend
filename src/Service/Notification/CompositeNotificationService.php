<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Composite Notification Service
 *
 * Allows combining multiple notification services (e.g., logs + email + Slack)
 * Notifications are sent to all registered services.
 *
 * Example usage:
 * ```php
 * $composite = new CompositeNotificationService([
 *     new LogNotificationService($logger),
 *     new EmailNotificationService($mailer),
 *     new SlackNotificationService($slackClient)
 * ]);
 * ```
 */
class CompositeNotificationService implements NotificationServiceInterface
{
    /**
     * @param NotificationServiceInterface[] $services
     */
    public function __construct(
        private readonly array $services
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function notify(
        string $message,
        NotificationLevel $level = NotificationLevel::INFO,
        array $context = []
    ): void {
        foreach ($this->services as $service) {
            try {
                $service->notify($message, $level, $context);
            } catch (\Throwable $e) {
                // Log failure but don't stop other notifications
                // This prevents a failing notification channel from blocking others
                error_log(sprintf(
                    'Notification service %s failed: %s',
                    get_class($service),
                    $e->getMessage()
                ));
            }
        }
    }
}
