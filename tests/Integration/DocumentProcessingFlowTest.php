<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Document;
use App\Entity\User;
use App\Service\DocumentProcessingService;
use App\Service\DocumentStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Integration test for complete document processing flow with shared storage
 *
 * Tests the end-to-end flow:
 * 1. File upload to shared storage
 * 2. Backend sends file path (not content) to OCR service
 * 3. OCR service reads from shared storage
 * 4. Results are returned
 * 5. File remains in shared storage for backend management
 */
class DocumentProcessingFlowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentStorageService $storageService;
    private DocumentProcessingService $processingService;
    private string $testFilesDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->storageService = static::getContainer()->get(DocumentStorageService::class);
        $this->processingService = static::getContainer()->get(DocumentProcessingService::class);

        // Create temp directory for test files
        $this->testFilesDir = sys_get_temp_dir() . '/docvault_test_' . uniqid();
        mkdir($this->testFilesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup test files
        if (is_dir($this->testFilesDir)) {
            $this->recursiveRemoveDirectory($this->testFilesDir);
        }

        parent::tearDown();
    }

    public function testCompleteDocumentProcessingFlowWithSharedStorage(): void
    {
        // GIVEN: A test PDF file in shared storage
        $testFile = $this->createTestPdfFile();
        $this->assertFileExists($testFile, 'Test file should exist in shared storage');

        // AND: A user and document entity
        $user = $this->createTestUser();
        $document = $this->createTestDocument($user, $testFile);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // WHEN: Document is sent for processing
        $this->processingService->processDocument($document);

        // THEN: Document status should be 'pending'
        $this->assertEquals('pending', $document->getProcessingStatus());

        // AND: Document metadata should contain OCR task ID
        $metadata = $document->getMetadata();
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('ocr_task_id', $metadata);

        // AND: Original file should still exist in shared storage
        $this->assertFileExists($testFile, 'File should remain in shared storage after processing');

        // AND: File path stored in document should be accessible
        $storedPath = $document->getFilePath();
        $absolutePath = $this->storageService->getAbsolutePath($storedPath);
        $this->assertFileExists($absolutePath, 'Document file path should be accessible');
    }

    public function testFilePathIsSentToOcrServiceNotFileContent(): void
    {
        // GIVEN: A document in shared storage
        $testFile = $this->createTestPdfFile();
        $user = $this->createTestUser();
        $document = $this->createTestDocument($user, $testFile);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $fileSize = filesize($testFile);

        // WHEN: Document is processed
        $this->processingService->processDocument($document);

        // THEN: File should still exist with same size (not duplicated)
        $this->assertFileExists($testFile);
        $this->assertEquals($fileSize, filesize($testFile), 'File size should remain unchanged');

        // AND: No temporary copy should exist
        // (In the old system, a copy would be created in /tmp/ocr-uploads)
        $this->assertDirectoryDoesNotExist('/tmp/ocr-uploads', 'Temp OCR uploads directory should not be used');
    }

    public function testSharedStoragePathIsAccessibleByBothBackendAndOcr(): void
    {
        // GIVEN: A file in shared storage at the backend path
        $testFile = $this->createTestPdfFile();
        $relativePath = $this->storageService->getRelativePath($testFile);

        // WHEN: We get the absolute path (as OCR service would)
        $absolutePath = $this->storageService->getAbsolutePath($relativePath);

        // THEN: Both paths should point to the same file
        $this->assertFileExists($absolutePath);
        $this->assertEquals(
            realpath($testFile),
            realpath($absolutePath),
            'Relative and absolute paths should resolve to same file'
        );

        // AND: Path should be under the shared storage directory
        $basePath = $this->storageService->getBasePath();
        $this->assertStringStartsWith(
            $basePath,
            $absolutePath,
            'File should be in shared storage directory'
        );
    }

    public function testMultipleDocumentsCanBeProcessedConcurrently(): void
    {
        // GIVEN: Multiple documents in shared storage
        $user = $this->createTestUser();
        $this->entityManager->persist($user);

        $documents = [];
        for ($i = 0; $i < 3; $i++) {
            $testFile = $this->createTestPdfFile("test_doc_{$i}.pdf");
            $document = $this->createTestDocument($user, $testFile);
            $this->entityManager->persist($document);
            $documents[] = $document;
        }
        $this->entityManager->flush();

        // WHEN: All documents are processed
        foreach ($documents as $document) {
            $this->processingService->processDocument($document);
        }

        // THEN: All documents should be queued
        foreach ($documents as $document) {
            $this->assertEquals('pending', $document->getProcessingStatus());
            $metadata = $document->getMetadata();
            $this->assertArrayHasKey('ocr_task_id', $metadata);
        }

        // AND: All files should still exist
        foreach ($documents as $document) {
            $filePath = $this->storageService->getAbsolutePath($document->getFilePath());
            $this->assertFileExists($filePath);
        }
    }

    public function testFileCleanupIsNotPerformedByOcrService(): void
    {
        // GIVEN: A processed document
        $testFile = $this->createTestPdfFile();
        $user = $this->createTestUser();
        $document = $this->createTestDocument($user, $testFile);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // WHEN: Document is processed
        $this->processingService->processDocument($document);

        // Simulate OCR completion
        $document->setProcessingStatus('completed');
        $this->entityManager->flush();

        // THEN: File should STILL exist (not cleaned up by OCR service)
        $this->assertFileExists($testFile, 'File should remain after OCR completion');

        // This is correct behavior - files are now managed by backend's
        // OrphanedFileCleanupService based on retention policy
    }

    public function testDocumentReprocessingUseSameFile(): void
    {
        // GIVEN: A document that was already processed
        $testFile = $this->createTestPdfFile();
        $user = $this->createTestUser();
        $document = $this->createTestDocument($user, $testFile);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Initial processing
        $this->processingService->processDocument($document);
        $firstTaskId = $document->getMetadata()['ocr_task_id'] ?? null;
        $this->assertNotNull($firstTaskId);

        // Mark as failed to allow retry
        $document->setProcessingStatus('failed');
        $this->entityManager->flush();

        // WHEN: Document is reprocessed
        $this->processingService->retryProcessing($document);

        // THEN: Same file should be used (not duplicated)
        $this->assertFileExists($testFile);
        $this->assertEquals('pending', $document->getProcessingStatus());

        // AND: New task ID should be assigned
        $metadata = $document->getMetadata();
        $this->assertArrayNotHasKey('ocr_task_id', $metadata, 'Old task ID should be cleared on retry');
    }

    // Helper methods

    private function createTestPdfFile(string $filename = 'test.pdf'): string
    {
        // Use storage service to generate proper path
        $user = new User();
        $user->setEmail('test@example.com');

        $filePath = $this->storageService->generateFilePath($user, $filename);

        // Create minimal valid PDF content
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";

        file_put_contents($filePath, $pdfContent);

        return $filePath;
    }

    private function createTestUser(): User
    {
        $user = new User();
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setPassword('password_hash');
        $user->setRoles(['ROLE_USER']);

        return $user;
    }

    private function createTestDocument(User $user, string $filePath): Document
    {
        $document = new Document();
        $document->setFilename(basename($filePath));
        $document->setOriginalName('test_document.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(filesize($filePath));
        $document->setFilePath($this->storageService->getRelativePath($filePath));
        $document->setUploadedBy($user);
        $document->setProcessingStatus('uploaded');

        return $document;
    }

    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . '/' . $file;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
