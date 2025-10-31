<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Message\IndexDocumentMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * OCR Webhook Controller
 *
 * Handles incoming webhook callbacks from OCR service
 * Replaces polling mechanism with push notifications
 */
#[Route('/api/webhooks/ocr', name: 'api_webhook_ocr_')]
class OcrWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret
    ) {
    }

    /**
     * Handle OCR processing webhook callback
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/callback', name: 'callback', methods: ['POST'])]
    public function handleCallback(Request $request): JsonResponse
    {
        // Start timing for latency tracking
        $startTime = microtime(true);
        $webhookId = uniqid('webhook_', true);

        try {
            // Log webhook receipt
            $this->logger->info('Webhook received', [
                'webhook_id' => $webhookId,
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s.u'),
                'ip_address' => $request->getClientIp()
            ]);

            // Validate webhook signature
            $signature = $request->headers->get('X-Webhook-Signature');
            if (!$signature) {
                $latency = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                $this->logger->warning('Webhook request missing signature', [
                    'webhook_id' => $webhookId,
                    'latency_ms' => round($latency, 2),
                    'result' => 'rejected_no_signature'
                ]);
                return $this->json([
                    'error' => 'Missing webhook signature'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Get raw content for signature validation
            $content = $request->getContent();

            // Validate signature
            if (!$this->validateSignature($content, $signature)) {
                $latency = (microtime(true) - $startTime) * 1000;
                $this->logger->warning('Invalid webhook signature', [
                    'webhook_id' => $webhookId,
                    'latency_ms' => round($latency, 2),
                    'result' => 'rejected_invalid_signature',
                    'signature_prefix' => substr($signature, 0, 8) . '...'
                ]);
                return $this->json([
                    'error' => 'Invalid webhook signature'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Parse JSON payload
            $payload = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $latency = (microtime(true) - $startTime) * 1000;
                $this->logger->error('Invalid JSON in webhook payload', [
                    'webhook_id' => $webhookId,
                    'latency_ms' => round($latency, 2),
                    'result' => 'error_invalid_json',
                    'json_error' => json_last_error_msg()
                ]);
                return $this->json([
                    'error' => 'Invalid JSON payload: ' . json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['task_id', 'document_id', 'status'];
            foreach ($requiredFields as $field) {
                if (!isset($payload[$field])) {
                    $latency = (microtime(true) - $startTime) * 1000;
                    $this->logger->error('Missing required field in webhook', [
                        'webhook_id' => $webhookId,
                        'latency_ms' => round($latency, 2),
                        'result' => 'error_missing_field',
                        'missing_field' => $field
                    ]);
                    return $this->json([
                        'error' => "Missing required field: {$field}"
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            $documentId = $payload['document_id'];
            $taskId = $payload['task_id'];
            $status = $payload['status'];

            // Find document
            $documentRepository = $this->entityManager->getRepository(Document::class);
            $document = $documentRepository->find($documentId);

            if (!$document) {
                $latency = (microtime(true) - $startTime) * 1000;
                $this->logger->warning('Webhook for non-existent document', [
                    'webhook_id' => $webhookId,
                    'latency_ms' => round($latency, 2),
                    'result' => 'error_document_not_found',
                    'document_id' => $documentId,
                    'task_id' => $taskId
                ]);
                return $this->json([
                    'error' => 'Document not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate task_id matches document metadata
            $metadata = $document->getMetadata() ?? [];
            $expectedTaskId = $metadata['ocr_task_id'] ?? null;

            if ($expectedTaskId && $expectedTaskId !== $taskId) {
                $this->logger->error('Task ID mismatch in webhook', [
                    'document_id' => $documentId,
                    'expected_task_id' => $expectedTaskId,
                    'received_task_id' => $taskId
                ]);
                return $this->json([
                    'error' => 'Task ID mismatch - webhook task_id does not match document'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Process webhook based on status
            $processingStartTime = microtime(true);

            if ($status === 'completed') {
                $this->handleCompletedWebhook($document, $payload);
            } elseif ($status === 'failed') {
                $this->handleFailedWebhook($document, $payload);
            } elseif ($status === 'processing') {
                $this->handleProgressUpdateWebhook($document, $payload);
            } else {
                $latency = (microtime(true) - $startTime) * 1000;
                $this->logger->warning('Unknown webhook status', [
                    'webhook_id' => $webhookId,
                    'latency_ms' => round($latency, 2),
                    'result' => 'error_unknown_status',
                    'status' => $status,
                    'document_id' => $documentId
                ]);
                return $this->json([
                    'error' => "Unknown status: {$status}"
                ], Response::HTTP_BAD_REQUEST);
            }

            $processingTime = (microtime(true) - $processingStartTime) * 1000;
            $totalLatency = (microtime(true) - $startTime) * 1000;

            $this->logger->info('Webhook processed successfully', [
                'webhook_id' => $webhookId,
                'document_id' => $documentId,
                'task_id' => $taskId,
                'status' => $status,
                'result' => 'success',
                'total_latency_ms' => round($totalLatency, 2),
                'processing_time_ms' => round($processingTime, 2),
                'validation_time_ms' => round($totalLatency - $processingTime, 2)
            ]);

            return $this->json([
                'message' => 'Webhook processed successfully',
                'document_id' => $documentId,
                'status' => $status
            ]);

        } catch (\Exception $e) {
            $latency = (microtime(true) - $startTime) * 1000;
            $this->logger->error('Error processing webhook', [
                'webhook_id' => $webhookId ?? 'unknown',
                'latency_ms' => round($latency, 2),
                'result' => 'error_exception',
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'error' => 'Internal server error processing webhook'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle completed OCR processing webhook
     *
     * @param Document $document
     * @param array $payload
     * @return void
     */
    private function handleCompletedWebhook(Document $document, array $payload): void
    {
        $result = $payload['result'] ?? [];

        // Store OCR text
        if (isset($result['text'])) {
            $document->setOcrText($result['text']);
        }

        // Store confidence score
        if (isset($result['confidence'])) {
            $confidence = $result['confidence'];
            // Ensure confidence is in 0-1 range
            if ($confidence > 1.0) {
                $confidence = $confidence / 100.0;
            }
            $document->setConfidenceScore((string) $confidence);
        }

        // Store language
        if (isset($result['language'])) {
            $document->setLanguage($result['language']);
        }

        // Store metadata
        $metadata = $document->getMetadata() ?? [];

        if (isset($result['metadata'])) {
            $metadata['extracted_metadata'] = $result['metadata'];
        }

        if (isset($result['category'])) {
            $metadata['category'] = $result['category'];
        }

        // Update completion timestamp
        $metadata['completed_at'] = (new \DateTime())->format('Y-m-d H:i:s');

        $document->setMetadata($metadata);

        // Extract structured metadata (dates, amounts)
        $this->extractStructuredMetadata($document);

        // Categorize document
        $this->categorizeDocument($document);

        // Build searchable content
        $this->buildSearchableContent($document);

        // Mark as completed
        $document->setProcessingStatus('completed');
        $document->setProcessingError(null);

        $this->entityManager->flush();

        // Dispatch message to index document in Meilisearch
        $this->messageBus->dispatch(new IndexDocumentMessage($document->getId()));

        $this->logger->info('Document processing completed via webhook', [
            'document_id' => $document->getId(),
            'confidence' => $document->getConfidenceScore()
        ]);
    }

    /**
     * Handle failed OCR processing webhook
     *
     * @param Document $document
     * @param array $payload
     * @return void
     */
    private function handleFailedWebhook(Document $document, array $payload): void
    {
        $error = $payload['error'] ?? 'OCR processing failed';

        $document->setProcessingStatus('failed');
        $document->setProcessingError($error);

        // Update metadata with failure info
        $metadata = $document->getMetadata() ?? [];
        $metadata['failed_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        $document->setMetadata($metadata);

        $this->entityManager->flush();

        $this->logger->error('Document processing failed via webhook', [
            'document_id' => $document->getId(),
            'error' => $error
        ]);
    }

    /**
     * Handle progress update webhook
     * Task 5.4: Accept progress updates
     *
     * @param Document $document
     * @param array $payload
     * @return void
     */
    private function handleProgressUpdateWebhook(Document $document, array $payload): void
    {
        // Validate progress value if provided
        if (isset($payload['progress'])) {
            $progress = (int) $payload['progress'];

            if ($progress < 0 || $progress > 100) {
                throw new \InvalidArgumentException('Progress must be between 0 and 100');
            }

            $document->setProgress($progress);
        }

        // Update current operation if provided
        if (isset($payload['current_operation'])) {
            $document->setCurrentOperation($payload['current_operation']);
        }

        // Ensure status is set to processing
        if ($document->getProcessingStatus() !== 'processing') {
            $document->setProcessingStatus('processing');
        }

        $this->entityManager->flush();

        $this->logger->info('Document progress updated via webhook', [
            'document_id' => $document->getId(),
            'progress' => $payload['progress'] ?? null,
            'operation' => $payload['current_operation'] ?? null
        ]);
    }

    /**
     * Validate HMAC signature
     *
     * @param string $payload
     * @param string $signature
     * @return bool
     */
    private function validateSignature(string $payload, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Extract structured metadata from OCR results
     *
     * @param Document $document
     * @return void
     */
    private function extractStructuredMetadata(Document $document): void
    {
        $metadata = $document->getMetadata() ?? [];
        $extractedMetadata = $metadata['extracted_metadata'] ?? [];

        // Extract dates (use first date found)
        if (!empty($extractedMetadata['dates'])) {
            $firstDate = $extractedMetadata['dates'][0];
            try {
                $date = new \DateTimeImmutable($firstDate);
                $document->setExtractedDate($date);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to parse extracted date', [
                    'document_id' => $document->getId(),
                    'date' => $firstDate,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Extract amounts (use largest amount found)
        if (!empty($extractedMetadata['amounts'])) {
            $amounts = $extractedMetadata['amounts'];
            $largestAmount = max($amounts);
            $document->setExtractedAmount((string) $largestAmount);
        }
    }

    /**
     * Categorize document based on OCR results
     *
     * @param Document $document
     * @return void
     */
    private function categorizeDocument(Document $document): void
    {
        $metadata = $document->getMetadata() ?? [];
        $categoryData = $metadata['category'] ?? null;

        if (!$categoryData || !isset($categoryData['primary_category'])) {
            return;
        }

        $categoryName = $categoryData['primary_category'];

        // Find or create category
        $categoryRepository = $this->entityManager->getRepository(\App\Entity\Category::class);
        $category = $categoryRepository->findOneBy(['name' => $categoryName]);

        if (!$category) {
            // Create new category
            $category = new \App\Entity\Category();
            $category->setId('cat-' . uniqid());
            $category->setName($categoryName);
            $category->setSlug(strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $categoryName)));
            $category->setDescription("Auto-generated category from document classification");
            $this->entityManager->persist($category);
        }

        $document->setCategory($category);
    }

    /**
     * Build searchable content from document data
     *
     * @param Document $document
     * @return void
     */
    private function buildSearchableContent(Document $document): void
    {
        $searchableContent = [];

        // Add OCR text
        if ($document->getOcrText()) {
            $searchableContent[] = $document->getOcrText();
        }

        // Add filename
        if ($document->getOriginalName()) {
            $searchableContent[] = $document->getOriginalName();
        }

        // Add extracted metadata
        $metadata = $document->getMetadata() ?? [];
        $extractedMetadata = $metadata['extracted_metadata'] ?? [];

        // Add invoice numbers
        if (!empty($extractedMetadata['invoice_numbers'])) {
            $searchableContent[] = implode(' ', $extractedMetadata['invoice_numbers']);
        }

        // Add names
        if (!empty($extractedMetadata['names'])) {
            $searchableContent[] = implode(' ', $extractedMetadata['names']);
        }

        // Add emails
        if (!empty($extractedMetadata['emails'])) {
            $searchableContent[] = implode(' ', $extractedMetadata['emails']);
        }

        // Add tax IDs
        if (!empty($extractedMetadata['tax_ids'])) {
            $searchableContent[] = implode(' ', $extractedMetadata['tax_ids']);
        }

        // Combine and normalize
        $content = implode(' ', $searchableContent);
        $content = preg_replace('/\s+/', ' ', $content); // Normalize whitespace
        $content = trim($content);

        $document->setSearchableContent($content);
    }
}
