<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for OCR Webhook Controller
 *
 * Tests webhook endpoint that receives callbacks from OCR service
 * following TDD - Red-Green-Refactor cycle
 */
class OcrWebhookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $testUser;
    private string $webhookSecret;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Get webhook secret from config (will be used for signature validation)
        $this->webhookSecret = $_ENV['OCR_WEBHOOK_SECRET'] ?? 'test_webhook_secret';

        // Create test user
        $this->testUser = new User();
        $this->testUser->setId('user-webhook-' . uniqid());
        $this->testUser->setEmail('webhook-test@example.com');
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if (isset($this->testUser)) {
            $this->entityManager->remove($this->testUser);
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    /**
     * Re-fetch document from database to get latest state
     */
    private function refreshDocument(Document $document): Document
    {
        return $this->entityManager->getRepository(Document::class)->find($document->getId());
    }

    private function createTestDocument(string $status = 'processing'): Document
    {
        $document = new Document();
        $document->setId('doc-webhook-' . uniqid());
        $document->setOriginalName('test-webhook-document.pdf');
        $document->setFilename('test-webhook-' . uniqid() . '.pdf');
        $document->setFilePath('/tmp/test-webhook.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(2048);
        $document->setProcessingStatus($status);
        $document->setUploadedBy($this->testUser);

        // Add OCR task metadata
        $document->setMetadata([
            'ocr_task_id' => 'task-' . uniqid(),
            'queued_at' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    private function createWebhookPayload(Document $document, string $status = 'completed'): array
    {
        $payload = [
            'task_id' => $document->getMetadata()['ocr_task_id'],
            'document_id' => $document->getId(),
            'status' => $status,
            'timestamp' => time()
        ];

        if ($status === 'completed') {
            $payload['result'] = [
                'text' => 'Invoice FV/2024/001 for Acme Corporation dated 2024-01-15 amount 5000.00 PLN',
                'confidence' => 0.95,
                'language' => 'pol',
                'metadata' => [
                    'invoice_numbers' => ['FV/2024/001'],
                    'names' => ['Acme Corporation'],
                    'dates' => ['2024-01-15'],
                    'amounts' => [5000.00],
                    'tax_ids' => ['123-456-78-90']
                ],
                'category' => [
                    'primary_category' => 'Invoice',
                    'confidence' => 0.98
                ]
            ];
        } elseif ($status === 'failed') {
            $payload['error'] = 'OCR processing failed: Unable to extract text from image';
        }

        return $payload;
    }

    private function signPayload(array $payload): string
    {
        $jsonPayload = json_encode($payload);
        return hash_hmac('sha256', $jsonPayload, $this->webhookSecret);
    }

    /**
     * TEST 1: Webhook endpoint requires valid signature
     */
    public function testWebhookRequiresValidSignature(): void
    {
        // GIVEN: A document processing in OCR service
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document);

        // WHEN: Webhook called without signature
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        // THEN: Request should be rejected
        $this->assertResponseStatusCodeSame(401);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('signature', strtolower($data['error']));

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 2: Webhook with invalid signature is rejected
     */
    public function testWebhookWithInvalidSignatureIsRejected(): void
    {
        // GIVEN: A document processing in OCR service
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document);

        // WHEN: Webhook called with invalid signature
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid_signature_12345',
        ], json_encode($payload));

        // THEN: Request should be rejected
        $this->assertResponseStatusCodeSame(401);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('invalid', strtolower($data['error']));

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 3: Webhook with valid signature updates document successfully
     */
    public function testWebhookWithValidSignatureUpdatesDocument(): void
    {
        // GIVEN: A document processing in OCR service
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document, 'completed');
        $signature = $this->signPayload($payload);

        // WHEN: Webhook called with valid signature
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should be successful
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('document_id', $data);
        $this->assertEquals($document->getId(), $data['document_id']);

        // Verify document was updated in database
        $document = $this->refreshDocument($document);
        $this->assertEquals('completed', $document->getProcessingStatus());
        $this->assertNotNull($document->getOcrText());
        $this->assertStringContainsString('Invoice FV/2024/001', $document->getOcrText());
        $this->assertEquals(0.95, $document->getConfidenceScore());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 4: Webhook for non-existent document returns 404
     */
    public function testWebhookForNonExistentDocumentReturns404(): void
    {
        // GIVEN: Webhook payload for non-existent document
        $payload = [
            'task_id' => 'task-non-existent',
            'document_id' => 'doc-non-existent-' . uniqid(),
            'status' => 'completed',
            'timestamp' => time(),
            'result' => [
                'text' => 'Test content',
                'confidence' => 0.9
            ]
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Webhook called
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return 404
        $this->assertResponseStatusCodeSame(404);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', strtolower($data['error']));
    }

    /**
     * TEST 5: Webhook with malformed JSON returns 400
     */
    public function testWebhookWithMalformedJsonReturns400(): void
    {
        // GIVEN: Invalid JSON payload
        $invalidJson = '{"task_id": "task-123", "status": "completed", invalid json}';

        // Generate valid signature for the malformed JSON content
        $signature = hash_hmac('sha256', $invalidJson, $this->webhookSecret);

        // WHEN: Webhook called with invalid JSON but valid signature
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $invalidJson);

        // THEN: Should return 400
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * TEST 6: Webhook with missing required fields returns 400
     */
    public function testWebhookWithMissingFieldsReturns400(): void
    {
        // GIVEN: Payload missing required fields
        $payload = [
            'task_id' => 'task-123',
            // Missing: document_id, status
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Webhook called
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return 400
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('required', strtolower($data['error']));
    }

    /**
     * TEST 7: Webhook is idempotent - can receive same callback multiple times
     */
    public function testWebhookIsIdempotent(): void
    {
        // GIVEN: A document processing in OCR service
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document, 'completed');
        $signature = $this->signPayload($payload);

        // WHEN: Same webhook is sent twice
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        $this->assertResponseIsSuccessful();
        $firstResponseData = json_decode($this->client->getResponse()->getContent(), true);

        // Send same webhook again
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Second call should also be successful
        $this->assertResponseIsSuccessful();
        $secondResponseData = json_decode($this->client->getResponse()->getContent(), true);

        // Document should still be in completed state - re-fetch from DB
        $documentId = $document->getId();
        $updatedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);
        $this->assertEquals('completed', $updatedDocument->getProcessingStatus());

        // Cleanup
        $this->entityManager->remove($updatedDocument);
        $this->entityManager->flush();
    }

    /**
     * TEST 8: Webhook handles failed OCR processing
     */
    public function testWebhookHandlesFailedProcessing(): void
    {
        // GIVEN: A document being processed
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document, 'failed');
        $signature = $this->signPayload($payload);

        // WHEN: Webhook indicates failure
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should update document to failed status
        $this->assertResponseIsSuccessful();

        $document = $this->refreshDocument($document);
        $this->assertEquals('failed', $document->getProcessingStatus());
        $this->assertNotNull($document->getProcessingError());
        $this->assertStringContainsString('OCR processing failed', $document->getProcessingError());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 9: Webhook stores all OCR metadata correctly
     */
    public function testWebhookStoresAllMetadataCorrectly(): void
    {
        // GIVEN: A document being processed
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document, 'completed');
        $signature = $this->signPayload($payload);

        // WHEN: Webhook received with complete metadata
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: All metadata should be stored
        $this->assertResponseIsSuccessful();

        $document = $this->refreshDocument($document);

        // Check OCR text
        $this->assertStringContainsString('Invoice FV/2024/001', $document->getOcrText());

        // Check confidence
        $this->assertEquals(0.95, $document->getConfidenceScore());

        // Check language
        $this->assertEquals('pol', $document->getLanguage());

        // Check extracted metadata
        $metadata = $document->getMetadata();
        $this->assertArrayHasKey('extracted_metadata', $metadata);
        $extractedMetadata = $metadata['extracted_metadata'];

        $this->assertArrayHasKey('invoice_numbers', $extractedMetadata);
        $this->assertContains('FV/2024/001', $extractedMetadata['invoice_numbers']);

        $this->assertArrayHasKey('names', $extractedMetadata);
        $this->assertContains('Acme Corporation', $extractedMetadata['names']);

        $this->assertArrayHasKey('amounts', $extractedMetadata);
        $this->assertContains(5000.00, $extractedMetadata['amounts']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 10: Webhook triggers document indexing in Meilisearch
     */
    public function testWebhookTriggersDocumentIndexing(): void
    {
        $this->markTestSkipped('Requires message bus spy/mock - implement after controller creation');

        // This test will verify that IndexDocumentMessage is dispatched
        // after successful webhook processing
    }

    /**
     * TEST 11: Webhook handles partial OCR results gracefully
     */
    public function testWebhookHandlesPartialResults(): void
    {
        // GIVEN: A document being processed
        $document = $this->createTestDocument('processing');

        // Payload with minimal data (no metadata or category)
        $payload = [
            'task_id' => $document->getMetadata()['ocr_task_id'],
            'document_id' => $document->getId(),
            'status' => 'completed',
            'timestamp' => time(),
            'result' => [
                'text' => 'Minimal OCR text',
                'confidence' => 0.75,
                // No metadata or category
            ]
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Webhook received
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should still complete successfully
        $this->assertResponseIsSuccessful();

        $document = $this->refreshDocument($document);
        $this->assertEquals('completed', $document->getProcessingStatus());
        $this->assertEquals('Minimal OCR text', $document->getOcrText());
        $this->assertEquals(0.75, $document->getConfidenceScore());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 12: Webhook validates task_id matches document metadata
     */
    public function testWebhookValidatesTaskIdMatch(): void
    {
        // GIVEN: A document with specific task_id
        $document = $this->createTestDocument('processing');

        // Payload with wrong task_id
        $payload = [
            'task_id' => 'wrong-task-id-12345',
            'document_id' => $document->getId(),
            'status' => 'completed',
            'timestamp' => time(),
            'result' => [
                'text' => 'Test',
                'confidence' => 0.9
            ]
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Webhook received with mismatched task_id
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return error
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('task', strtolower($data['error']));

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 13: Webhook returns 200 with processing message
     */
    public function testWebhookReturnsSuccessMessage(): void
    {
        // GIVEN: A document processing in OCR service
        $document = $this->createTestDocument('processing');
        $payload = $this->createWebhookPayload($document, 'completed');
        $signature = $this->signPayload($payload);

        // WHEN: Webhook called
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return success with details
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('document_id', $data);
        $this->assertArrayHasKey('status', $data);

        $this->assertEquals($document->getId(), $data['document_id']);
        $this->assertEquals('completed', $data['status']);
        $this->assertStringContainsString('success', strtolower($data['message']));

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 14: Progress update webhook updates document progress
     * Task 5.1: Test progress update webhook payload
     */
    public function testProgressUpdateWebhookUpdatesDocumentProgress(): void
    {
        // GIVEN: Document in processing state
        $document = $this->createTestDocument('doc-progress-' . uniqid());
        $document->setProcessingStatus('processing');
        $this->entityManager->flush();

        // AND: Progress update webhook payload
        $payload = [
            'task_id' => 'task-progress-123',
            'document_id' => $document->getId(),
            'status' => 'processing',
            'progress' => 50,
            'current_operation' => 'Extracting text from page 5/10',
            'timestamp' => time(),
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Progress webhook called
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return success
        $this->assertResponseIsSuccessful();

        // AND: Document progress should be updated
        $document = $this->refreshDocument($document);
        $this->assertEquals(50, $document->getProgress());
        $this->assertEquals('Extracting text from page 5/10', $document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 15: Multiple progress updates track progression
     */
    public function testMultipleProgressUpdatesTrackProgression(): void
    {
        // GIVEN: Document in processing state
        $document = $this->createTestDocument('doc-multi-progress-' . uniqid());
        $document->setProcessingStatus('processing');
        $this->entityManager->flush();

        // WHEN: Multiple progress updates sent (25%, 50%, 75%)
        $progressUpdates = [
            ['progress' => 25, 'operation' => 'Converting PDF pages'],
            ['progress' => 50, 'operation' => 'Performing OCR'],
            ['progress' => 75, 'operation' => 'Extracting metadata'],
        ];

        foreach ($progressUpdates as $update) {
            $payload = [
                'task_id' => 'task-multi-progress',
                'document_id' => $document->getId(),
                'status' => 'processing',
                'progress' => $update['progress'],
                'current_operation' => $update['operation'],
                'timestamp' => time(),
            ];
            $signature = $this->signPayload($payload);

            $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ], json_encode($payload));

            $this->assertResponseIsSuccessful();
            $document = $this->refreshDocument($document);
            $this->assertEquals($update['progress'], $document->getProgress());
        }

        // THEN: Final progress should be 75%
        $this->assertEquals(75, $document->getProgress());
        $this->assertEquals('Extracting metadata', $document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 16: Progress update with invalid percentage returns error
     */
    public function testProgressUpdateWithInvalidPercentageReturnsError(): void
    {
        // GIVEN: Document in processing state
        $document = $this->createTestDocument('doc-invalid-progress-' . uniqid());
        $document->setProcessingStatus('processing');
        $this->entityManager->flush();

        // AND: Progress update with invalid percentage (>100)
        $payload = [
            'task_id' => 'task-invalid-progress',
            'document_id' => $document->getId(),
            'status' => 'processing',
            'progress' => 150,  // Invalid: > 100
            'current_operation' => 'Processing',
            'timestamp' => time(),
        ];
        $signature = $this->signPayload($payload);

        // WHEN: Webhook called
        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Should return 400 Bad Request
        $this->assertResponseStatusCodeSame(400);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * TEST 17: Progress update preserves existing OCR data
     */
    public function testProgressUpdatePreservesExistingOcrData(): void
    {
        // GIVEN: Document with some OCR text already stored
        $document = $this->createTestDocument('doc-preserve-' . uniqid());
        $document->setProcessingStatus('processing');
        $document->setOcrText('Partial OCR text');
        $document->setConfidenceScore('0.85');
        $this->entityManager->flush();

        // WHEN: Progress update sent
        $payload = [
            'task_id' => 'task-preserve',
            'document_id' => $document->getId(),
            'status' => 'processing',
            'progress' => 60,
            'current_operation' => 'Continuing OCR',
            'timestamp' => time(),
        ];
        $signature = $this->signPayload($payload);

        $this->client->request('POST', '/api/webhooks/ocr/callback', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], json_encode($payload));

        // THEN: Progress updated but OCR data preserved
        $this->assertResponseIsSuccessful();
        $document = $this->refreshDocument($document);
        $this->assertEquals(60, $document->getProgress());
        $this->assertEquals('Partial OCR text', $document->getOcrText());
        $this->assertEquals(0.85, $document->getConfidenceScore());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }
}
