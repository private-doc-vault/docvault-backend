<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * OCR API Client Service
 *
 * Handles communication with the OCR service for task management
 * including finding and resetting stuck tasks
 */
class OcrApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $ocrServiceUrl
    ) {
    }

    /**
     * Find stuck tasks in the OCR service
     *
     * @param int $timeoutMinutes Timeout threshold in minutes
     * @return array<string> List of stuck task IDs
     * @throws \RuntimeException If communication with OCR service fails
     */
    public function findStuckTasks(int $timeoutMinutes): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->ocrServiceUrl}/api/v1/tasks/stuck", [
                'query' => [
                    'timeout_minutes' => $timeoutMinutes,
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException("OCR service returned unexpected status code: {$statusCode}");
            }

            $data = $response->toArray();

            if (!isset($data['stuck_tasks']) || !is_array($data['stuck_tasks'])) {
                $this->logger->warning('OCR service response missing stuck_tasks field', [
                    'response' => $data,
                ]);
                return [];
            }

            $stuckTasks = $data['stuck_tasks'];

            $this->logger->info('Retrieved stuck tasks from OCR service', [
                'count' => count($stuckTasks),
                'timeout_minutes' => $timeoutMinutes,
            ]);

            return $stuckTasks;

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to communicate with OCR service', [
                'error' => $e->getMessage(),
                'url' => "{$this->ocrServiceUrl}/api/v1/tasks/stuck",
            ]);
            throw new \RuntimeException('OCR service unavailable: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $this->logger->error('Error finding stuck tasks', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Error finding stuck tasks: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reset a stuck task by re-queuing it
     *
     * @param string $taskId Task ID to reset
     * @return bool True if task was successfully reset, false otherwise
     */
    public function resetStuckTask(string $taskId): bool
    {
        try {
            $response = $this->httpClient->request('POST', "{$this->ocrServiceUrl}/api/v1/tasks/{$taskId}/reset", [
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $this->logger->info('Successfully reset stuck task', [
                    'task_id' => $taskId,
                ]);
                return true;
            }

            $this->logger->warning('Failed to reset stuck task', [
                'task_id' => $taskId,
                'status_code' => $statusCode,
            ]);
            return false;

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to reset stuck task', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Error resetting stuck task', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get queue statistics from OCR service
     *
     * @return array<string, int> Queue statistics
     * @throws \RuntimeException If communication with OCR service fails
     */
    public function getQueueStatistics(): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->ocrServiceUrl}/api/v1/queue/statistics", [
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException("OCR service returned unexpected status code: {$statusCode}");
            }

            $data = $response->toArray();

            $this->logger->info('Retrieved queue statistics from OCR service', [
                'statistics' => $data,
            ]);

            return $data;

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Failed to retrieve queue statistics', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('OCR service unavailable: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $this->logger->error('Error retrieving queue statistics', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Error retrieving queue statistics: ' . $e->getMessage(), 0, $e);
        }
    }
}
