<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\OcrApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/monitoring/queue')]
class QueueMonitoringController extends AbstractController
{
    public function __construct(
        private readonly OcrApiClient $ocrApiClient,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/statistics', name: 'api_queue_statistics', methods: ['GET'])]
    public function getStatistics(): JsonResponse
    {
        try {
            $statistics = $this->ocrApiClient->getQueueStatistics();

            return $this->json($statistics);

        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to retrieve queue statistics', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/health', name: 'api_queue_health', methods: ['GET'])]
    public function getHealth(): JsonResponse
    {
        try {
            $statistics = $this->ocrApiClient->getQueueStatistics();

            // Determine health status based on metrics
            $status = 'healthy';
            $issues = [];

            // Check for stuck tasks
            if (isset($statistics['stuck']) && $statistics['stuck'] > 3) {
                $status = 'warning';
                $issues[] = "High number of stuck tasks: {$statistics['stuck']}";
            }

            // Check for high DLQ count
            if (isset($statistics['dead_letter_queue']) && $statistics['dead_letter_queue'] > 20) {
                $status = 'critical';
                $issues[] = "High dead letter queue count: {$statistics['dead_letter_queue']}";
            }

            $response = [
                'status' => $status,
                'timestamp' => (new \DateTime())->format(\DateTime::ISO8601),
            ];

            if (!empty($issues)) {
                $response['issues'] = $issues;
            }

            return $this->json($response);

        } catch (\RuntimeException $e) {
            return $this->json([
                'status' => 'unavailable',
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/stuck-tasks', name: 'api_queue_stuck_tasks', methods: ['GET'])]
    public function getStuckTasks(Request $request): JsonResponse
    {
        try {
            $timeout = (int) $request->query->get('timeout', 30);

            $stuckTasks = $this->ocrApiClient->findStuckTasks($timeout);

            return $this->json([
                'stuck_tasks' => $stuckTasks,
                'count' => count($stuckTasks),
                'timeout_minutes' => $timeout,
            ]);

        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to retrieve stuck tasks', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => $e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
