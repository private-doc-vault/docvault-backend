<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for Document Processing Status API
 *
 * Tests the processing status tracking and retry functionality
 */
class DocumentProcessingStatusApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $testUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Clean up any existing test user first
        $this->cleanupTestUser();

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('processing-test@example.com');
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();

        // Authenticate
        $this->client->loginUser($this->testUser);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupTestUser();

        parent::tearDown();
    }

    private function cleanupTestUser(): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'processing-test@example.com']);
        if ($user) {
            // Remove any documents associated with this user
            $documents = $this->entityManager->getRepository(Document::class)->findBy(['uploadedBy' => $user]);
            foreach ($documents as $document) {
                $this->entityManager->remove($document);
            }
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }
    }

    private function createTestDocument(string $status = 'uploaded'): Document
    {
        $document = new Document();
        $document->setOriginalName('test-document.pdf');
        $document->setFilename('test-' . uniqid() . '.pdf');
        $document->setFilePath('/tmp/test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setProcessingStatus($status);
        $document->setUploadedBy($this->testUser);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    public function testGetProcessingStatusRequiresAuthentication(): void
    {
        $document = $this->createTestDocument();
        $documentId = $document->getId();

        // Create a new unauthenticated client
        $unauthenticatedClient = static::createClient();

        $unauthenticatedClient->request('GET', '/api/documents/' . $documentId . '/processing-status');

        $this->assertResponseStatusCodeSame(401);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testGetProcessingStatusReturnsDocumentStatus(): void
    {
        $document = $this->createTestDocument('completed');
        $document->setOcrText('Sample OCR text');
        $document->setConfidenceScore('0.95');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('document_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('ocrText', $data);
        $this->assertArrayHasKey('confidence_score', $data);

        $this->assertEquals($document->getId(), $data['document_id']);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('Sample OCR text', $data['ocrText']);
        $this->assertEquals(0.95, $data['confidence_score']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testGetProcessingStatusForNonExistentDocument(): void
    {
        $this->client->request('GET', '/api/documents/non-existent-id/processing-status');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRetryProcessingRequiresAuthentication(): void
    {
        $document = $this->createTestDocument('failed');
        $documentId = $document->getId();

        // Create a new unauthenticated client
        $unauthenticatedClient = static::createClient();

        $unauthenticatedClient->request('POST', '/api/documents/' . $documentId . '/retry-processing');

        $this->assertResponseStatusCodeSame(401);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testRetryProcessingForFailedDocument(): void
    {
        $document = $this->createTestDocument('failed');
        $document->setProcessingError('OCR service timeout');
        $this->entityManager->flush();

        $this->client->request('POST', '/api/documents/' . $document->getId() . '/retry-processing');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('document_id', $data);
        $this->assertArrayHasKey('status', $data);

        $this->assertEquals($document->getId(), $data['document_id']);
        $this->assertStringContainsString('retry', strtolower($data['message']));

        // Verify document status was reset
        $this->entityManager->refresh($document);
        $this->assertNotEquals('failed', $document->getProcessingStatus());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testRetryProcessingForNonFailedDocumentReturnsError(): void
    {
        $document = $this->createTestDocument('completed');

        $this->client->request('POST', '/api/documents/' . $document->getId() . '/retry-processing');

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('current_status', $data);
        $this->assertEquals('completed', $data['current_status']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testRetryProcessingForNonExistentDocument(): void
    {
        $this->client->request('POST', '/api/documents/non-existent-id/retry-processing');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testProcessingStatusIncludesMetadata(): void
    {
        $document = $this->createTestDocument('completed');

        $metadata = [
            'ocr_task_id' => 'task-123',
            'progress' => 100
        ];
        $document->setMetadata($metadata);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('progress', $data);
        $this->assertArrayHasKey('task_id', $data);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testProcessingStatusIncludesErrorForFailedDocuments(): void
    {
        $document = $this->createTestDocument('failed');
        $document->setProcessingError('Connection timeout to OCR service');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Connection timeout to OCR service', $data['error']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    public function testProcessingStatusIncludesExtractedMetadata(): void
    {
        $document = $this->createTestDocument('completed');
        $document->setExtractedAmount('1500.00');
        $document->setExtractedDate(new \DateTimeImmutable('2024-01-15'));
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('extracted_amount', $data);
        $this->assertArrayHasKey('extracted_date', $data);

        $this->assertEquals('1500.00', $data['extracted_amount']);
        $this->assertEquals('2024-01-15', $data['extracted_date']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test progress field in processing status response
     */
    public function testProcessingStatusReturnsProgressForProcessingDocument(): void
    {
        $document = $this->createTestDocument('processing');
        $document->setProgress(45);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('progress', $data);
        $this->assertEquals(45, $data['progress']);
        $this->assertEquals('processing', $data['status']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test current_operation field in processing status response
     */
    public function testProcessingStatusReturnsCurrentOperationForProcessingDocument(): void
    {
        $document = $this->createTestDocument('processing');
        $document->setProgress(60);
        $document->setCurrentOperation('Performing OCR on page 6/10');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('current_operation', $data);
        $this->assertArrayHasKey('progress', $data);
        $this->assertEquals('Performing OCR on page 6/10', $data['current_operation']);
        $this->assertEquals(60, $data['progress']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test progress at different stages of processing
     */
    public function testProgressTrackingThroughProcessingStages(): void
    {
        $document = $this->createTestDocument('processing');

        // Stage 1: Initial conversion (25%)
        $document->setProgress(25);
        $document->setCurrentOperation('Converting document to images');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(25, $data['progress']);
        $this->assertEquals('Converting document to images', $data['current_operation']);

        // Stage 2: OCR processing (50%)
        $document->setProgress(50);
        $document->setCurrentOperation('Performing OCR on page 5/10');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(50, $data['progress']);
        $this->assertEquals('Performing OCR on page 5/10', $data['current_operation']);

        // Stage 3: Metadata extraction (85%)
        $document->setProgress(85);
        $document->setCurrentOperation('Extracting metadata');
        $this->entityManager->flush();
        $this->entityManager->clear(); // Clear entity manager cache

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(85, $data['progress']);
        $this->assertEquals('Extracting metadata', $data['current_operation']);

        // Cleanup
        $documentId = $document->getId();
        $documentToRemove = $this->entityManager->getRepository(Document::class)->find($documentId);
        if ($documentToRemove) {
            $this->entityManager->remove($documentToRemove);
            $this->entityManager->flush();
        }
    }

    /**
     * Task 5.5: Test that completed documents have 100% progress
     */
    public function testCompletedDocumentHasFullProgress(): void
    {
        $document = $this->createTestDocument('completed');
        $document->setProgress(100);
        $document->setOcrText('Sample completed text');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('completed', $data['status']);
        $this->assertEquals(100, $data['progress']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test that uploaded documents have 0% progress
     */
    public function testUploadedDocumentHasZeroProgress(): void
    {
        $document = $this->createTestDocument('uploaded');
        $document->setProgress(0);
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('uploaded', $data['status']);
        $this->assertEquals(0, $data['progress']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test current_operation is null when not processing
     */
    public function testCurrentOperationIsNullForNonProcessingDocument(): void
    {
        $document = $this->createTestDocument('uploaded');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('current_operation', $data);
        $this->assertNull($data['current_operation']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Task 5.5: Test queued document shows initial state
     */
    public function testQueuedDocumentShowsInitialProgress(): void
    {
        $document = $this->createTestDocument('queued');
        $document->setProgress(0);
        $document->setCurrentOperation('Waiting in queue');
        $this->entityManager->flush();

        $this->client->request('GET', '/api/documents/' . $document->getId() . '/processing-status');

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('queued', $data['status']);
        $this->assertEquals(0, $data['progress']);
        $this->assertEquals('Waiting in queue', $data['current_operation']);

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }
}
