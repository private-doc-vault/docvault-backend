<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Psr\Log\LoggerInterface;

/**
 * Log-based Notification Service
 *
 * Default implementation that sends notifications via PSR-3 logger.
 * This provides a solid foundation for monitoring and alerting by ensuring
 * all critical events are logged with appropriate severity levels.
 *
 * External monitoring systems can be configured to watch logs and trigger
 * alerts based on log levels and patterns.
 */
class LogNotificationService implements NotificationServiceInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
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
        // Enrich context with notification metadata
        $enrichedContext = $this->enrichContext($context, $level);

        // Log using appropriate PSR-3 method
        $logMethod = $level->getLogMethod();
        $this->logger->{$logMethod}($message, $enrichedContext);
    }

    /**
     * Enrich context with notification metadata
     *
     * @param array<string, mixed> $context
     * @param NotificationLevel $level
     * @return array<string, mixed>
     */
    private function enrichContext(array $context, NotificationLevel $level): array
    {
        $enriched = $context;

        // Add notification level
        $enriched['notification_level'] = $level->value;

        // Add timestamp
        $enriched['notification_timestamp'] = (new \DateTime())->format('Y-m-d H:i:s.u');

        // Extract exception details if present
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $enriched['exception_message'] = $exception->getMessage();
            $enriched['exception_code'] = $exception->getCode();
            $enriched['exception_class'] = get_class($exception);
        }

        return $enriched;
    }
}
