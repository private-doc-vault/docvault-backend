<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Notification\LogNotificationService;
use App\Service\Notification\NotificationLevel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotificationServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private LogNotificationService $notificationService;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->notificationService = new LogNotificationService($this->logger);
    }

    public function testNotifyLogsInfoLevelMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Test notification',
                $this->callback(function ($context) {
                    return isset($context['notification_level'])
                        && $context['notification_level'] === 'info';
                })
            );

        $this->notificationService->notify(
            'Test notification',
            NotificationLevel::INFO
        );
    }

    public function testNotifyLogsWarningLevelMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Warning notification',
                $this->callback(function ($context) {
                    return isset($context['notification_level'])
                        && $context['notification_level'] === 'warning';
                })
            );

        $this->notificationService->notify(
            'Warning notification',
            NotificationLevel::WARNING
        );
    }

    public function testNotifyLogsErrorLevelMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Error notification',
                $this->callback(function ($context) {
                    return isset($context['notification_level'])
                        && $context['notification_level'] === 'error';
                })
            );

        $this->notificationService->notify(
            'Error notification',
            NotificationLevel::ERROR
        );
    }

    public function testNotifyLogsCriticalLevelMessage(): void
    {
        $this->logger->expects($this->once())
            ->method('critical')
            ->with(
                'Critical notification',
                $this->callback(function ($context) {
                    return isset($context['notification_level'])
                        && $context['notification_level'] === 'critical';
                })
            );

        $this->notificationService->notify(
            'Critical notification',
            NotificationLevel::CRITICAL
        );
    }

    public function testNotifyIncludesAdditionalContext(): void
    {
        $context = [
            'document_id' => 123,
            'error_code' => 'OCR_FAILED'
        ];

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Processing failed',
                $this->callback(function ($logContext) use ($context) {
                    return $logContext['notification_level'] === 'error'
                        && $logContext['document_id'] === 123
                        && $logContext['error_code'] === 'OCR_FAILED';
                })
            );

        $this->notificationService->notify(
            'Processing failed',
            NotificationLevel::ERROR,
            $context
        );
    }

    public function testNotifyCriticalFailureWithException(): void
    {
        $exception = new \RuntimeException('Test exception', 500);

        $this->logger->expects($this->once())
            ->method('critical')
            ->with(
                'Circuit breaker opened',
                $this->callback(function ($context) {
                    return isset($context['notification_level'])
                        && $context['notification_level'] === 'critical'
                        && isset($context['exception'])
                        && $context['exception'] instanceof \RuntimeException
                        && isset($context['exception_message'])
                        && $context['exception_message'] === 'Test exception';
                })
            );

        $this->notificationService->notify(
            'Circuit breaker opened',
            NotificationLevel::CRITICAL,
            ['exception' => $exception]
        );
    }

    public function testNotifyAddsTimestamp(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Test message',
                $this->callback(function ($context) {
                    return isset($context['notification_timestamp'])
                        && is_string($context['notification_timestamp']);
                })
            );

        $this->notificationService->notify(
            'Test message',
            NotificationLevel::INFO
        );
    }

    public function testNotifyPreservesExistingContext(): void
    {
        $context = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Test message',
                $this->callback(function ($logContext) use ($context) {
                    return $logContext['key1'] === 'value1'
                        && $logContext['key2'] === 'value2'
                        && isset($logContext['notification_level'])
                        && isset($logContext['notification_timestamp']);
                })
            );

        $this->notificationService->notify(
            'Test message',
            NotificationLevel::INFO,
            $context
        );
    }

    public function testNotifyDefaultsToInfoLevel(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Default level message',
                $this->anything()
            );

        $this->notificationService->notify('Default level message');
    }

    public function testNotifyExtractsExceptionDetailsWhenPresent(): void
    {
        $exception = new \InvalidArgumentException('Invalid input', 400);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Validation error',
                $this->callback(function ($context) {
                    return isset($context['exception_message'])
                        && $context['exception_message'] === 'Invalid input'
                        && isset($context['exception_code'])
                        && $context['exception_code'] === 400
                        && isset($context['exception_class'])
                        && $context['exception_class'] === \InvalidArgumentException::class;
                })
            );

        $this->notificationService->notify(
            'Validation error',
            NotificationLevel::ERROR,
            ['exception' => $exception]
        );
    }
}
