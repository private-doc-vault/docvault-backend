<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\User;
use App\Message\IndexDocumentMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Functional test for complete webhook callback flow
 *
 * Tests the end-to-end webhook flow:
 * 1. OCR service completes processing
 * 2. OCR service sends webhook to backend
 * 3. Backend receives and validates webhook (HMAC signature)
 * 4. Backend updates document status and stores OCR results
 * 5. Backend dispatches IndexDocumentMessage for search indexing
 * 6. Document becomes searchable
 *
 * This test verifies Task 3.12: Complete webhook flow integration
 */
class WebhookCallbackFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $webhookSecret;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->webhookSecret = $_ENV['OCR_WEBHOOK_SECRET'] ?? 'test-webhook-secret-key';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test complete webhook flow: OCR completion -> webhook -> backend update -> indexing
     */
    public function testCompleteWebhookFlowFromOcrCompletionToBackendUpdate(): void
    {
        // GIVEN: A document that was sent for OCR processing
        $user = $this->createTestUser();
        $document = $this->createProcessingDocument($user, 'invoice.pdf');
        $document->setMetadata(['ocr_task_id' => 'task-123']);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $documentId = $document->getId();

        // AND: OCR service completed processing
        $webhookPayload = [
            'document_id' => $documentId,
            'task_id' => 'task-123',
            'status' => 'completed',
            'result' => [
                'text' => 'Invoice FV/2024/001 for Acme Corp dated 2024-01-15 amount 5000.00 PLN',
                'confidence_score' => '0.95',
                'page_count' => 1,
                'processing_time' => 2.5
            ],
            'metadata' => [
                'invoice_numbers' => ['FV/2024/001'],
                'names' => ['Acme Corp'],
                'dates' => ['2024-01-15'],
                'amounts' => [5000.00]
            ],
            'timestamp' => time()
        ];

        // WHEN: OCR service sends webhook to backend
        $signature = $this->generateHmacSignature($webhookPayload);

        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        // THEN: Backend should accept webhook (200 OK)
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);

        // AND: Document status should be updated to 'completed'
        $this->entityManager->clear(); // Clear to force fresh fetch
        $updatedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);

        $this->assertNotNull($updatedDocument, 'Document should still exist');
        $this->assertEquals('completed', $updatedDocument->getProcessingStatus());

        // AND: OCR text should be stored
        $this->assertEquals(
            'Invoice FV/2024/001 for Acme Corp dated 2024-01-15 amount 5000.00 PLN',
            $updatedDocument->getOcrText()
        );

        // AND: Confidence score should be stored
        $this->assertEquals('0.95', $updatedDocument->getConfidenceScore());

        // AND: Metadata should be stored
        $metadata = $updatedDocument->getMetadata();
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('extracted_metadata', $metadata);
        $this->assertEquals(['FV/2024/001'], $metadata['extracted_metadata']['invoice_numbers']);
        $this->assertEquals(['Acme Corp'], $metadata['extracted_metadata']['names']);

        // AND: IndexDocumentMessage should be dispatched to async queue
        $transport = static::getContainer()->get('messenger.transport.async_indexing');
        if ($transport instanceof InMemoryTransport) {
            $messages = $transport->get();
            $this->assertCount(1, $messages, 'IndexDocumentMessage should be dispatched');

            $envelope = $messages[0];
            $message = $envelope->getMessage();
            $this->assertInstanceOf(IndexDocumentMessage::class, $message);
            $this->assertEquals($documentId, $message->getDocumentId());
        }

        // Cleanup - re-fetch user after clear()
        $userId = $user->getId();
        $userToRemove = $this->entityManager->getRepository(User::class)->find($userId);
        $this->entityManager->remove($updatedDocument);
        if ($userToRemove) {
            $this->entityManager->remove($userToRemove);
        }
        $this->entityManager->flush();
    }

    /**
     * Test webhook with failed OCR processing
     */
    public function testWebhookHandlesFailedOcrProcessing(): void
    {
        // GIVEN: A document that was sent for OCR processing
        $user = $this->createTestUser();
        $document = $this->createProcessingDocument($user, 'corrupted.pdf');
        $document->setMetadata(['ocr_task_id' => 'task-456']);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $documentId = $document->getId();

        // AND: OCR service failed to process the document
        $webhookPayload = [
            'document_id' => $documentId,
            'task_id' => 'task-456',
            'status' => 'failed',
            'error' => 'File is corrupted or unreadable',
            'timestamp' => time()
        ];

        // WHEN: OCR service sends failure webhook
        $signature = $this->generateHmacSignature($webhookPayload);

        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        // THEN: Backend should accept webhook
        $this->assertResponseIsSuccessful();

        // AND: Document status should be 'failed'
        $this->entityManager->clear();
        $updatedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);

        $this->assertEquals('failed', $updatedDocument->getProcessingStatus());

        // AND: Error message should be stored in document
        $this->assertEquals('File is corrupted or unreadable', $updatedDocument->getProcessingError());

        // AND: No IndexDocumentMessage should be dispatched for failed documents
        $transport = static::getContainer()->get('messenger.transport.async_indexing');
        if ($transport instanceof InMemoryTransport) {
            $messages = $transport->get();
            $this->assertCount(0, $messages, 'Failed documents should not be indexed');
        }

        // Cleanup - re-fetch user after clear()
        $userId = $user->getId();
        $userToRemove = $this->entityManager->getRepository(User::class)->find($userId);
        $this->entityManager->remove($updatedDocument);
        if ($userToRemove) {
            $this->entityManager->remove($userToRemove);
        }
        $this->entityManager->flush();
    }

    /**
     * Test webhook signature validation rejects invalid signatures
     */
    public function testWebhookRejectsInvalidSignature(): void
    {
        // GIVEN: A valid webhook payload
        $user = $this->createTestUser();
        $document = $this->createProcessingDocument($user, 'test.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $documentId = $document->getId();

        $webhookPayload = [
            'document_id' => $documentId,
            'task_id' => 'task-789',
            'status' => 'completed',
            'result' => ['text' => 'Test content'],
            'timestamp' => time()
        ];

        // WHEN: Webhook is sent with invalid signature
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature-12345',
            ],
            json_encode($webhookPayload)
        );

        // THEN: Backend should reject with 401 Unauthorized
        $this->assertResponseStatusCodeSame(401);

        // AND: Document status should NOT be updated
        $this->entityManager->clear();
        $unchangedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);
        $this->assertEquals('processing', $unchangedDocument->getProcessingStatus());

        // Cleanup - re-fetch user after clear()
        $userId = $user->getId();
        $userToRemove = $this->entityManager->getRepository(User::class)->find($userId);
        $this->entityManager->remove($unchangedDocument);
        $this->entityManager->remove($userToRemove);
        $this->entityManager->flush();
    }

    /**
     * Test webhook rejects requests without signature header
     */
    public function testWebhookRejectsMissingSignature(): void
    {
        // GIVEN: A webhook payload
        $webhookPayload = [
            'document_id' => 'doc-123',
            'task_id' => 'task-999',
            'status' => 'completed',
            'timestamp' => time()
        ];

        // WHEN: Webhook is sent without signature header
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($webhookPayload)
        );

        // THEN: Backend should reject with 401 Unauthorized
        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Test webhook is idempotent (can receive same callback multiple times)
     */
    public function testWebhookIsIdempotent(): void
    {
        // GIVEN: A document that was sent for OCR processing
        $user = $this->createTestUser();
        $document = $this->createProcessingDocument($user, 'document.pdf');
        $document->setMetadata(['ocr_task_id' => 'task-idempotent']);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $documentId = $document->getId();

        $webhookPayload = [
            'document_id' => $documentId,
            'task_id' => 'task-idempotent',
            'status' => 'completed',
            'result' => [
                'text' => 'Test document content',
                'confidence_score' => '0.92'
            ],
            'timestamp' => time()
        ];

        $signature = $this->generateHmacSignature($webhookPayload);

        // WHEN: Webhook is sent first time
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        $this->assertResponseIsSuccessful();

        // AND: Webhook is sent second time (duplicate delivery)
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        // THEN: Second request should also succeed (idempotent)
        $this->assertResponseIsSuccessful();

        // AND: Document should still be in correct state
        $this->entityManager->clear();
        $updatedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);
        $this->assertEquals('completed', $updatedDocument->getProcessingStatus());
        $this->assertEquals('Test document content', $updatedDocument->getOcrText());

        // Cleanup - re-fetch user after clear()
        $userId = $user->getId();
        $userToRemove = $this->entityManager->getRepository(User::class)->find($userId);
        $this->entityManager->remove($updatedDocument);
        $this->entityManager->remove($userToRemove);
        $this->entityManager->flush();
    }

    /**
     * Test webhook handles document not found gracefully
     */
    public function testWebhookHandlesDocumentNotFound(): void
    {
        // GIVEN: A webhook for non-existent document
        $webhookPayload = [
            'document_id' => 'non-existent-doc-id',
            'task_id' => 'task-404',
            'status' => 'completed',
            'result' => ['text' => 'Some content'],
            'timestamp' => time()
        ];

        $signature = $this->generateHmacSignature($webhookPayload);

        // WHEN: Webhook is sent
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        // THEN: Backend should return 404 Not Found
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Test webhook handles malformed JSON payload
     */
    public function testWebhookHandlesMalformedJson(): void
    {
        // GIVEN: Malformed JSON payload
        $malformedJson = '{"document_id": "test", "status": incomplete}'; // Missing quotes

        $signature = hash_hmac('sha256', $malformedJson, $this->webhookSecret);

        // WHEN: Webhook is sent with malformed JSON
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $malformedJson
        );

        // THEN: Backend should return 400 Bad Request
        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * Test webhook handles missing required fields
     */
    public function testWebhookHandlesMissingRequiredFields(): void
    {
        // GIVEN: Payload missing required fields
        $incompletePayload = [
            'task_id' => 'task-incomplete',
            // Missing document_id and status
            'timestamp' => time()
        ];

        $signature = $this->generateHmacSignature($incompletePayload);

        // WHEN: Webhook is sent
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($incompletePayload)
        );

        // THEN: Backend should return 400 Bad Request
        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * Test webhook with partial OCR results (processing update, not completion)
     */
    public function testWebhookHandlesProcessingProgressUpdate(): void
    {
        // GIVEN: A document being processed
        $user = $this->createTestUser();
        $document = $this->createProcessingDocument($user, 'large_doc.pdf');
        $document->setMetadata(['ocr_task_id' => 'task-progress']);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $documentId = $document->getId();

        // AND: OCR service sends progress update (50% complete)
        $webhookPayload = [
            'document_id' => $documentId,
            'task_id' => 'task-progress',
            'status' => 'processing',
            'progress' => 50,
            'message' => 'Processing page 5 of 10',
            'timestamp' => time()
        ];

        $signature = $this->generateHmacSignature($webhookPayload);

        // WHEN: Progress webhook is sent
        $this->client->request(
            'POST',
            '/api/webhooks/ocr/callback',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            json_encode($webhookPayload)
        );

        // THEN: Backend should accept webhook
        $this->assertResponseIsSuccessful();

        // AND: Document should remain in 'processing' status
        $this->entityManager->clear();
        $updatedDocument = $this->entityManager->getRepository(Document::class)->find($documentId);
        $this->assertEquals('processing', $updatedDocument->getProcessingStatus());

        // AND: Progress should be stored in document
        $this->assertEquals(50, $updatedDocument->getProgress());

        // Cleanup - re-fetch user after clear()
        $userId = $user->getId();
        $userToRemove = $this->entityManager->getRepository(User::class)->find($userId);
        $this->entityManager->remove($updatedDocument);
        if ($userToRemove) {
            $this->entityManager->remove($userToRemove);
        }
        $this->entityManager->flush();
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    private function createTestUser(): User
    {
        $user = new User();
        $user->setId('user-' . uniqid());
        $user->setEmail('webhook-test-' . uniqid() . '@example.com');
        $user->setPassword('hashed_password');
        $user->setRoles(['ROLE_USER']);

        return $user;
    }

    private function createProcessingDocument(User $user, string $filename): Document
    {
        $document = new Document();
        $document->setId('doc-' . uniqid());
        $document->setFilename($filename);
        $document->setOriginalName($filename);
        $document->setMimeType('application/pdf');
        $document->setFileSize(2048);
        $document->setFilePath('/storage/documents/' . $filename);
        $document->setUploadedBy($user);
        $document->setProcessingStatus('processing');

        return $document;
    }

    private function generateHmacSignature(array $payload): string
    {
        $jsonPayload = json_encode($payload);
        return hash_hmac('sha256', $jsonPayload, $this->webhookSecret);
    }
}
