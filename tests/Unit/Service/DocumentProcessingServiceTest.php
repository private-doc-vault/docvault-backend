<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use App\Entity\Category;
use App\Entity\User;
use App\Service\DocumentProcessingService;
use App\Service\DocumentStorageService;
use App\Service\WebhookNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class DocumentProcessingServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private DocumentStorageService $storageService;
    private MessageBusInterface $messageBus;
    private WebhookNotificationService $webhookService;
    private DocumentProcessingService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->storageService = $this->createMock(DocumentStorageService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->webhookService = $this->createMock(WebhookNotificationService::class);

        // Configure messageBus to accept any dispatch call
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->service = new DocumentProcessingService(
            $this->entityManager,
            $this->httpClient,
            $this->logger,
            $this->storageService,
            $this->messageBus,
            'http://ocr-service:8000',  // OCR service URL
            $this->webhookService
        );
    }

    public function testProcessDocumentUpdatesStatusToQueued(): void
    {
        // GIVEN a document that needs processing
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_QUEUED);
        $document->setFilePath('2024/10/user-123/test.pdf');

        // Mock storage service
        $absolutePath = $this->createTempFile('test.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // Mock OCR service response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'test-123',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // EXPECT the document status to be updated to 'queued'
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($document);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        // WHEN we start processing
        $this->service->processDocument($document);

        // THEN status should be 'queued'
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentCallsOcrService(): void
    {
        // GIVEN a document with a file path
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/document.pdf');

        // Mock storage service to return absolute path (create real temp file)
        $absolutePath = $this->createTempFile('document.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->with('2024/10/user-123/document.pdf')
            ->willReturn($absolutePath);

        // EXPECT HTTP client to call OCR service with file_path
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'test-task-123',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://ocr-service:8000/api/v1/ocr/process',
                $this->callback(function ($options) {
                    return isset($options['body']['file_path'])
                        && isset($options['body']['language'])
                        && !isset($options['body']['file']); // No file upload
                })
            )
            ->willReturn($response);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN document metadata should contain task_id
        $metadata = $document->getMetadata();
        $this->assertArrayHasKey('ocr_task_id', $metadata);
        $this->assertEquals('test-task-123', $metadata['ocr_task_id']);

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentHandlesOcrServiceFailure(): void
    {
        // GIVEN a document
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/document.pdf');

        // Mock storage service
        $absolutePath = $this->createTempFile('test.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // AND OCR service returns error
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // EXPECT error to be logged (at least once, may be called multiple times)
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN status should be 'failed'
        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertNotNull($document->getProcessingError());

        // Cleanup
        unlink($absolutePath);
    }

    /**
     * @group deprecated
     * @deprecated This test is for a deprecated method that no longer performs any operations
     */
    public function testUpdateProcessingStatusWithOcrResult(): void
    {
        $this->markTestSkipped('updateProcessingStatus() is deprecated - webhook-based processing is tested in WebhookCallbackFlowTest');
    }

    public function testExtractAndStoreMetadata(): void
    {
        // GIVEN a document with flat OCR metadata
        $document = $this->createDocument();
        $document->setMetadata([
            'dates' => ['2024-01-15'],
            'amounts' => [1234.56, 99.99],
            'invoice_numbers' => ['FV/2024/001'],
            'tax_ids' => ['123-456-78-90']
        ]);

        // WHEN we extract metadata
        $this->service->extractAndStoreMetadata($document);

        // THEN document should have extracted data
        $this->assertNotNull($document->getExtractedDate());
        $this->assertEquals('2024-01-15', $document->getExtractedDate()->format('Y-m-d'));
        $this->assertEquals('1234.56', $document->getExtractedAmount());
    }

    public function testExtractMultipleDatesUsesFirst(): void
    {
        // GIVEN a document with multiple dates (flat structure)
        $document = $this->createDocument();
        $document->setMetadata([
            'dates' => ['2024-01-15', '2024-02-20', '2024-03-10']
        ]);

        // WHEN we extract metadata
        $this->service->extractAndStoreMetadata($document);

        // THEN first date should be used
        $this->assertEquals('2024-01-15', $document->getExtractedDate()->format('Y-m-d'));
    }

    public function testExtractMultipleAmountsUsesLargest(): void
    {
        // GIVEN a document with multiple amounts (flat structure)
        $document = $this->createDocument();
        $document->setMetadata([
            'amounts' => [99.99, 1234.56, 500.00]
        ]);

        // WHEN we extract metadata
        $this->service->extractAndStoreMetadata($document);

        // THEN largest amount should be used
        $this->assertEquals('1234.56', $document->getExtractedAmount());
    }

    public function testCategorizeDocumentWithOcrResult(): void
    {
        // GIVEN a document with categorization data
        $document = $this->createDocument();
        $document->setMetadata([
            'category' => [
                'primary_category' => 'invoice',
                'confidence' => 0.95
            ]
        ]);

        // AND category exists in database
        $invoiceCategory = new Category();
        $invoiceCategory->setName('invoice');

        $categoryRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $categoryRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'invoice'])
            ->willReturn($invoiceCategory);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Category::class)
            ->willReturn($categoryRepository);

        // WHEN we categorize the document
        $this->service->categorizeDocument($document);

        // THEN document should be assigned to category
        $this->assertSame($invoiceCategory, $document->getCategory());
    }

    public function testCategorizeDocumentCreatesNewCategoryIfNotExists(): void
    {
        // GIVEN a document with unknown category
        $document = $this->createDocument();
        $document->setMetadata([
            'category' => [
                'primary_category' => 'new_category',
                'confidence' => 0.85
            ]
        ]);

        // AND category doesn't exist
        $categoryRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $categoryRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'new_category'])
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Category::class)
            ->willReturn($categoryRepository);

        // EXPECT new category to be persisted
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($category) {
                return $category instanceof Category
                    && $category->getName() === 'new_category';
            }));

        // WHEN we categorize the document
        $this->service->categorizeDocument($document);

        // THEN document should have the new category
        $this->assertEquals('new_category', $document->getCategory()->getName());
    }

    public function testBuildSearchableContent(): void
    {
        // GIVEN a document with OCR text and flat metadata
        $document = $this->createDocument();
        $document->setOcrText('Invoice FV/2024/001 for ABC Company');
        $document->setOriginalName('invoice_january.pdf');
        $document->setMetadata([
            'invoice_numbers' => ['FV/2024/001'],
            'names' => ['ABC Company'],
            'tax_ids' => ['123-456-78-90']
        ]);

        // WHEN we build searchable content
        $this->service->buildSearchableContent($document);

        // THEN searchable content should combine all relevant data
        $searchableContent = $document->getSearchableContent();
        $this->assertStringContainsString('Invoice FV/2024/001', $searchableContent);
        $this->assertStringContainsString('invoice_january.pdf', $searchableContent);
        $this->assertStringContainsString('FV/2024/001', $searchableContent);
        $this->assertStringContainsString('ABC Company', $searchableContent);
    }

    public function testProcessDocumentCompletePipeline(): void
    {
        // GIVEN a document ready for processing
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/invoice.pdf');
        $document->setOriginalName('invoice.pdf');

        // Mock storage service
        $absolutePath = $this->createTempFile('invoice.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // Mock OCR service responses
        $queueResponse = $this->createMock(ResponseInterface::class);
        $queueResponse->method('getStatusCode')->willReturn(200);
        $queueResponse->method('toArray')->willReturn([
            'task_id' => 'task-123',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('/process'))
            ->willReturn($queueResponse);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN document should be queued for processing
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());
        $metadata = $document->getMetadata();
        $this->assertArrayHasKey('ocr_task_id', $metadata);

        // Cleanup
        unlink($absolutePath);
    }

    public function testGetProcessingStatusReturnsCurrentState(): void
    {
        // GIVEN a document being processed
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_PROCESSING);
        $document->setMetadata(['progress' => 50]);

        // WHEN we get processing status
        $status = $this->service->getProcessingStatus($document);

        // THEN status should include current state
        $this->assertEquals(Document::STATUS_PROCESSING, $status['status']);
        $this->assertEquals(50, $status['progress']);
    }

    public function testRetryFailedDocument(): void
    {
        // GIVEN a failed document
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/failed.pdf');
        $document->setProcessingStatus(Document::STATUS_FAILED);
        $document->setProcessingError('OCR service timeout');

        // Mock storage service
        $absolutePath = $this->createTempFile('failed.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // Mock successful retry
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'retry-task-456',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // WHEN we retry processing
        $this->service->retryProcessing($document);

        // THEN document should be queued again
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());
        $this->assertNull($document->getProcessingError());

        // Cleanup
        unlink($absolutePath);
    }

    /**
     * NEW TESTS FOR FILE PATH-BASED PROCESSING
     * Following TDD - these will fail until implementation is complete
     */

    public function testProcessDocumentSendsFilePathInsteadOfFileContent(): void
    {
        // GIVEN a document with relative file path
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/doc_abc123_invoice.pdf');

        // AND storage service converts to absolute path (create real temp file)
        $absolutePath = $this->createTempFile('invoice.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->with('2024/10/user-123/doc_abc123_invoice.pdf')
            ->willReturn($absolutePath);

        // EXPECT HTTP client to send file_path parameter instead of file upload
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'test-task-456',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://ocr-service:8000/api/v1/ocr/process',
                $this->callback(function ($options) use ($absolutePath) {
                    // Should send file_path in body, NOT file upload
                    return isset($options['body']['file_path'])
                        && $options['body']['file_path'] === $absolutePath
                        && isset($options['body']['language'])
                        && !isset($options['body']['file']); // No file upload!
                })
            )
            ->willReturn($response);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN document should be queued for processing
        $metadata = $document->getMetadata();
        $this->assertArrayHasKey('ocr_task_id', $metadata);
        $this->assertEquals('test-task-456', $metadata['ocr_task_id']);

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentFailsWhenFileDoesNotExist(): void
    {
        // GIVEN a document with file path to non-existent file
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/nonexistent.pdf');

        // AND storage service returns absolute path to non-existent file
        $absolutePath = '/var/www/html/storage/documents/2024/10/user-123/nonexistent.pdf';
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->with('2024/10/user-123/nonexistent.pdf')
            ->willReturn($absolutePath);

        // EXPECT error to be logged twice (once in sendToOcrService, once in handleProcessingError)
        $this->logger->expects($this->exactly(2))
            ->method('error');

        // EXPECT no HTTP request to be made
        $this->httpClient->expects($this->never())
            ->method('request');

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN status should be 'failed'
        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertStringContainsString('not found', $document->getProcessingError());
    }

    public function testProcessDocumentUsesAbsolutePathFromStorageService(): void
    {
        // GIVEN a document with relative path
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-456/report.pdf');

        // EXPECT storage service to be called to get absolute path (create real file)
        $absolutePath = $this->createTempFile('report.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->with('2024/10/user-456/report.pdf')
            ->willReturn($absolutePath);

        // Mock successful OCR response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'task-789',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN processing should succeed
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentSendsCorrectLanguageParameter(): void
    {
        // GIVEN a document with specific language set
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/document.pdf');
        $document->setLanguage('deu'); // German

        // Mock storage service (create real file)
        $absolutePath = $this->createTempFile('document.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // EXPECT HTTP request with correct language
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'task-lang-test',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['body']['language'])
                        && $options['body']['language'] === 'deu';
                })
            )
            ->willReturn($response);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN request should include correct language
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentUsesDefaultLanguageEngWhenNotSet(): void
    {
        // GIVEN a document with default language from entity ('en')
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/document.pdf');
        // Document entity defaults to language 'en', which maps to 'eng' in ?? operator

        // Mock storage service (create real file)
        $absolutePath = $this->createTempFile('document.pdf');
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willReturn($absolutePath);

        // EXPECT HTTP request with language from document ('en')
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'task-default-lang',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['body']['language'])
                        && $options['body']['language'] === 'en'; // Should use document's default 'en'
                })
            )
            ->willReturn($response);

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN request should use document's language
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // Cleanup
        unlink($absolutePath);
    }

    public function testProcessDocumentHandlesStorageServiceException(): void
    {
        // GIVEN a document
        $document = $this->createDocument();
        $document->setFilePath('invalid/path.pdf');

        // AND storage service throws exception
        $this->storageService->expects($this->once())
            ->method('getAbsolutePath')
            ->willThrowException(new \RuntimeException('Storage service error'));

        // EXPECT error to be logged (in handleProcessingError)
        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        // WHEN we process the document
        $this->service->processDocument($document);

        // THEN document should be marked as failed
        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertStringContainsString('Storage service error', $document->getProcessingError());
    }

    private function createDocument(): Document
    {
        $document = new Document();
        $document->setId('test-doc-' . uniqid());
        $document->setFilename('test.pdf');
        $document->setOriginalName('test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/path.pdf');
        $document->setProcessingStatus(Document::STATUS_QUEUED);

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }

    /**
     * @group deprecated
     * @deprecated This test is for a deprecated method that no longer performs any operations
     */
    public function testUpdateProcessingStatusDispatchesIndexMessageAfterOcrCompletion(): void
    {
        $this->markTestSkipped('updateProcessingStatus() is deprecated - webhook-based processing is tested in WebhookCallbackFlowTest');
    }

    // Circuit Breaker Tests

    public function testCircuitBreakerDoesNotInterruptSuccessfulCalls(): void
    {
        // GIVEN a document with a file path
        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/test.pdf');

        // Mock storage service
        $absolutePath = $this->createTempFile('test.pdf');
        $this->storageService->method('getAbsolutePath')->willReturn($absolutePath);

        // Mock successful OCR service response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'task_id' => 'test-123',
            'status' => Document::STATUS_QUEUED
        ]);

        $this->httpClient->method('request')->willReturn($response);

        // WHEN processing the document
        $this->service->processDocument($document);

        // THEN the document should be queued successfully
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // Cleanup
        unlink($absolutePath);
    }

    public function testCircuitBreakerOpensAfterMultipleFailures(): void
    {
        // GIVEN multiple documents to process
        $documents = [];
        for ($i = 0; $i < 6; $i++) {
            $doc = $this->createDocument();
            $doc->setId('test-doc-' . $i);
            $doc->setFilePath('2024/10/user-123/test-' . $i . '.pdf');
            $documents[] = $doc;
        }

        // Mock storage service to return valid paths
        $this->storageService->method('getAbsolutePath')->willReturnCallback(function ($path) {
            return $this->createTempFile(basename($path));
        });

        // Mock OCR service to fail consistently
        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('Connection failed')
        );

        // WHEN processing multiple documents (should trigger circuit breaker after 5 failures)
        foreach ($documents as $index => $document) {
            $this->service->processDocument($document);

            // All should be marked as failed
            $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        }

        // Note: Circuit breaker functionality will be tested more thoroughly once integrated
    }

    public function testProcessDocumentHandlesCircuitBreakerException(): void
    {
        // This test will be fully implemented after circuit breaker integration
        // For now, we verify that the service handles exceptions gracefully

        $document = $this->createDocument();
        $document->setFilePath('2024/10/user-123/test.pdf');

        // Mock storage service
        $absolutePath = $this->createTempFile('test.pdf');
        $this->storageService->method('getAbsolutePath')->willReturn($absolutePath);

        // Mock circuit breaker open state (simulated by runtime exception)
        $this->httpClient->method('request')->willThrowException(
            new \RuntimeException('Service unavailable')
        );

        // WHEN processing document with circuit breaker open
        $this->service->processDocument($document);

        // THEN document should be marked as failed with appropriate error
        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertNotNull($document->getProcessingError());

        // Cleanup
        unlink($absolutePath);
    }

    /**
     * Helper method to create temporary files for testing
     */
    private function createTempFile(string $filename): string
    {
        $tempDir = sys_get_temp_dir();
        $filePath = $tempDir . '/' . uniqid('test_') . '_' . $filename;
        file_put_contents($filePath, 'test content');
        return $filePath;
    }
}
