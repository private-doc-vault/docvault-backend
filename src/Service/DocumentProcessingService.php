<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Category;
use App\Message\IndexDocumentMessage;
use App\Message\UpdateProcessingStatusMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Document Processing Service
 *
 * Handles async document processing including:
 * - OCR text extraction
 * - Metadata extraction
 * - Automatic categorization
 * - Searchable content generation
 */
class DocumentProcessingService
{
    // Status constants aligned with OCR service (TaskStatus enum)
    private const STATUS_QUEUED = 'queued';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    private readonly CircuitBreaker $circuitBreaker;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly DocumentStorageService $storageService,
        private readonly MessageBusInterface $messageBus,
        private readonly string $ocrServiceUrl,
        private readonly ?WebhookNotificationService $webhookService = null,
        ?CircuitBreaker $circuitBreaker = null
    ) {
        // Initialize circuit breaker with default settings if not provided
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker(
            $logger,
            failureThreshold: 5,
            resetTimeout: 60
        );
    }

    /**
     * Process a document through the OCR pipeline
     *
     * @param Document $document
     * @return void
     */
    public function processDocument(Document $document): void
    {
        try {
            // Update status to queued
            $document->setProcessingStatus(self::STATUS_QUEUED);
            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->logger->info('Starting document processing', [
                'document_id' => $document->getId(),
                'filename' => $document->getFilename()
            ]);

            // Send document to OCR service
            $this->sendToOcrService($document);

        } catch (\Exception $e) {
            $this->handleProcessingError($document, $e);
        }
    }

    /**
     * Map ISO 639-1 (2-letter) language codes to ISO 639-2/T (3-letter) codes used by Tesseract
     *
     * @param string $language
     * @return string
     */
    private function mapLanguageCode(string $language): string
    {
        $languageMap = [
            'en' => 'eng',  // English
            'de' => 'deu',  // German
            'fr' => 'fra',  // French
            'es' => 'spa',  // Spanish
            'it' => 'ita',  // Italian
            'pt' => 'por',  // Portuguese
            'pl' => 'pol',  // Polish
        ];

        return $languageMap[$language] ?? $language;
    }

    /**
     * Send document to OCR service for processing using file path
     *
     * @param Document $document
     * @return void
     */
    private function sendToOcrService(Document $document): void
    {
        try {
            // Get relative path from database and convert to absolute path
            $relativePath = $document->getFilePath();
            $absolutePath = $this->storageService->getAbsolutePath($relativePath);

            if (!file_exists($absolutePath)) {
                $this->logger->error('Document file not found', [
                    'document_id' => $document->getId(),
                    'absolute_path' => $absolutePath,
                    'relative_path' => $relativePath
                ]);
                throw new \RuntimeException("Document file not found: {$absolutePath}");
            }

            // Map language code from 2-letter to 3-letter format
            $language = $this->mapLanguageCode($document->getLanguage() ?? 'pl');

            // Wrap OCR service call in circuit breaker
            $result = $this->circuitBreaker->call(function () use ($document, $absolutePath, $language) {
                // Prepare request with file_path instead of file upload
                $response = $this->httpClient->request('POST', "{$this->ocrServiceUrl}/api/v1/ocr/process", [
                    'body' => [
                        'file_path' => $absolutePath,
                        'language' => $language,  // Use mapped 3-letter language code
                        'document_id' => $document->getId()  // Send document ID for webhook callbacks
                    ]
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200) {
                    throw new \RuntimeException("OCR service returned status code: {$statusCode}");
                }

                return $response->toArray();
            });

            // Store task ID in metadata
            $metadata = $document->getMetadata() ?? [];
            $metadata['ocr_task_id'] = $result['task_id'];
            $metadata['ocr_status'] = $result['status'] ?? 'queued';
            $metadata['queued_at'] = (new \DateTime())->format('Y-m-d H:i:s');

            $document->setMetadata($metadata);
            $this->entityManager->flush();

            $this->logger->info('Document queued for OCR processing', [
                'document_id' => $document->getId(),
                'task_id' => $result['task_id'],
                'file_path' => $absolutePath
            ]);

            // No need to schedule status updates - OCR service will send webhook when complete

        } catch (CircuitBreakerException $e) {
            $this->logger->error('OCR service circuit breaker is open', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage()
            ]);

            $document->setProcessingStatus(self::STATUS_FAILED);
            $document->setProcessingError('OCR service temporarily unavailable. Please try again later.');
            $this->entityManager->flush();
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('OCR service request failed', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage()
            ]);

            $document->setProcessingStatus(self::STATUS_FAILED);
            $document->setProcessingError('OCR service unavailable: ' . $e->getMessage());
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to send document to OCR service', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage()
            ]);

            $document->setProcessingStatus(self::STATUS_FAILED);
            $document->setProcessingError($e->getMessage());
            $this->entityManager->flush();
        }
    }

    /**
     * Update processing status by checking OCR service
     *
     * @deprecated This method is deprecated and will be removed in the next version.
     *             OCR service now sends webhook notifications instead of polling.
     *             The webhook is handled by OcrWebhookController.
     *
     * @param Document $document
     * @return void
     */
    public function updateProcessingStatus(Document $document): void
    {
        $this->logger->warning('updateProcessingStatus() is deprecated - webhooks should be used instead', [
            'document_id' => $document->getId()
        ]);

        // This method is kept for backward compatibility but should not be used
        // Webhook notifications via OcrWebhookController have replaced the polling mechanism
        return;
    }

    /**
     * Handle OCR processing result
     *
     * Stores metadata in flat structure (no "extracted_metadata" nesting)
     *
     * @param Document $document
     * @param array $result
     * @return void
     */
    private function handleOcrResult(Document $document, array $result): void
    {
        // Store OCR text
        $document->setOcrText($result['text'] ?? '');

        // Store confidence score (convert to 0-1 range if needed)
        if (isset($result['confidence'])) {
            $confidence = $result['confidence'];
            if ($confidence > 1.0) {
                $confidence = $confidence / 100.0;
            }
            $document->setConfidenceScore($confidence);
        }

        // Store language
        if (isset($result['language'])) {
            $document->setLanguage($result['language']);
        }

        // Store extracted metadata in flat structure
        $metadata = $document->getMetadata() ?? [];

        // Merge OCR metadata fields at top level (flat structure)
        if (isset($result['metadata'])) {
            $metadata = array_merge($metadata, $result['metadata']);
        }

        // Store category information at top level
        if (isset($result['category'])) {
            $metadata['category'] = $result['category'];
        }

        $document->setMetadata($metadata);

        // Extract and store structured metadata
        $this->extractAndStoreMetadata($document);

        // Categorize document
        $this->categorizeDocument($document);

        // Build searchable content
        $this->buildSearchableContent($document);

        // Mark as completed
        $document->setProcessingStatus(self::STATUS_COMPLETED);
        $document->setProcessingError(null);

        $this->entityManager->flush();

        $this->logger->info('Document processing completed', [
            'document_id' => $document->getId(),
            'confidence' => $document->getConfidenceScore()
        ]);

        // Dispatch message to index document in Meilisearch
        $this->messageBus->dispatch(new IndexDocumentMessage($document->getId()));

        // Send webhook notification
        $this->webhookService?->notifyProcessingComplete($document);
    }

    /**
     * Extract and store structured metadata from OCR results
     *
     * Metadata is stored in flat structure (no "extracted_metadata" nesting)
     *
     * @param Document $document
     * @return void
     */
    public function extractAndStoreMetadata(Document $document): void
    {
        $metadata = $document->getMetadata() ?? [];

        // Extract dates (use first date found)
        if (!empty($metadata['dates'])) {
            $firstDate = $metadata['dates'][0];
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
        if (!empty($metadata['amounts'])) {
            $amounts = $metadata['amounts'];
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
    public function categorizeDocument(Document $document): void
    {
        $metadata = $document->getMetadata() ?? [];
        $categoryData = $metadata['category'] ?? null;

        if (!$categoryData || !isset($categoryData['primary_category'])) {
            return;
        }

        $categoryName = $categoryData['primary_category'];

        // Find or create category
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        $category = $categoryRepository->findOneBy(['name' => $categoryName]);

        if (!$category) {
            // Create new category
            $category = new Category();
            $category->setName($categoryName);
            $category->setDescription("Auto-generated category from document classification");
            $this->entityManager->persist($category);
        }

        $document->setCategory($category);
    }

    /**
     * Build searchable content from document data
     *
     * Metadata is accessed in flat structure (no "extracted_metadata" nesting)
     *
     * @param Document $document
     * @return void
     */
    public function buildSearchableContent(Document $document): void
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

        // Add extracted metadata (flat structure)
        $metadata = $document->getMetadata() ?? [];

        // Add invoice numbers
        if (!empty($metadata['invoice_numbers'])) {
            $searchableContent[] = implode(' ', $metadata['invoice_numbers']);
        }

        // Add names
        if (!empty($metadata['names'])) {
            $searchableContent[] = implode(' ', $metadata['names']);
        }

        // Add emails
        if (!empty($metadata['emails'])) {
            $searchableContent[] = implode(' ', $metadata['emails']);
        }

        // Add tax IDs
        if (!empty($metadata['tax_ids'])) {
            $searchableContent[] = implode(' ', $metadata['tax_ids']);
        }

        // Combine and normalize
        $content = implode(' ', $searchableContent);
        $content = preg_replace('/\s+/', ' ', $content); // Normalize whitespace
        $content = trim($content);

        $document->setSearchableContent($content);
    }

    /**
     * Get current processing status
     *
     * @param Document $document
     * @return array
     */
    public function getProcessingStatus(Document $document): array
    {
        $metadata = $document->getMetadata() ?? [];

        return [
            'status' => $document->getProcessingStatus(),
            'progress' => $metadata['progress'] ?? 0,
            'error' => $document->getProcessingError(),
            'task_id' => $metadata['ocr_task_id'] ?? null
        ];
    }

    /**
     * Retry processing for failed document
     *
     * @param Document $document
     * @return void
     */
    public function retryProcessing(Document $document): void
    {
        $this->logger->info('Retrying document processing', [
            'document_id' => $document->getId(),
            'previous_error' => $document->getProcessingError()
        ]);

        // Reset status and error
        $document->setProcessingStatus(self::STATUS_QUEUED);
        $document->setProcessingError(null);

        // Clear previous OCR task ID
        $metadata = $document->getMetadata() ?? [];
        unset($metadata['ocr_task_id']);
        $document->setMetadata($metadata);

        $this->entityManager->flush();

        // Restart processing
        $this->processDocument($document);
    }

    /**
     * Handle processing error
     *
     * @param Document $document
     * @param \Exception $exception
     * @return void
     */
    private function handleProcessingError(Document $document, \Exception $exception): void
    {
        $this->logger->error('Document processing error', [
            'document_id' => $document->getId(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $document->setProcessingStatus(self::STATUS_FAILED);
        $document->setProcessingError($exception->getMessage());

        $this->entityManager->flush();

        // Send webhook notification
        $this->webhookService?->notifyProcessingFailed($document);
    }

    /**
     * Schedule a status update check
     *
     * @deprecated This method is deprecated and will be removed in the next version.
     *             OCR service now sends webhook notifications, no polling needed.
     *
     * @param string $documentId
     * @param int $delayMs Delay in milliseconds
     * @return void
     */
    private function scheduleStatusUpdate(string $documentId, int $delayMs): void
    {
        // This method is deprecated - webhooks replace polling
        // Kept for backward compatibility but does nothing
        $this->logger->debug('scheduleStatusUpdate() called but is deprecated - webhooks are used instead', [
            'document_id' => $documentId,
            'delay_ms' => $delayMs
        ]);
    }
}
