<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use App\Entity\User;
use App\Service\DocumentStorageService;
use App\Service\OrphanedFileCleanupService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OrphanedFileCleanupService
 *
 * Tests cover:
 * - Detection of orphaned files (files without database records)
 * - Detection of missing files (database records without files)
 * - Cleanup of orphaned files
 * - Dry run mode for safe previewing
 * - Statistics reporting
 * - Error handling
 */
class OrphanedFileCleanupServiceTest extends TestCase
{
    private DocumentStorageService $storageService;
    private EntityManagerInterface $entityManager;
    private OrphanedFileCleanupService $cleanupService;
    private string $testBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary test directory
        $this->testBasePath = sys_get_temp_dir() . '/docvault_cleanup_test_' . uniqid();
        mkdir($this->testBasePath, 0755, true);

        // Mock entity manager and repository
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $documentRepository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($documentRepository);

        // Real storage service for testing
        $this->storageService = new DocumentStorageService($this->testBasePath);

        // Service under test
        $this->cleanupService = new OrphanedFileCleanupService(
            $this->entityManager,
            $this->storageService
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testBasePath)) {
            $this->recursiveRemoveDirectory($this->testBasePath);
        }

        parent::tearDown();
    }

    public function testFindOrphanedFilesReturnsEmptyWhenNoFiles(): void
    {
        // No files on filesystem, no documents in DB
        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        $orphanedFiles = $this->cleanupService->findOrphanedFiles();

        $this->assertIsArray($orphanedFiles);
        $this->assertCount(0, $orphanedFiles);
    }

    public function testFindOrphanedFilesDetectsFilesWithoutDatabaseRecords(): void
    {
        // Create physical files
        $file1 = $this->createTestFile('2024/01/user-123/test1.pdf');
        $file2 = $this->createTestFile('2024/01/user-123/test2.pdf');
        $file3 = $this->createTestFile('2024/02/user-456/test3.pdf');

        // No documents in database
        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        $orphanedFiles = $this->cleanupService->findOrphanedFiles();

        $this->assertCount(3, $orphanedFiles);
        $this->assertContains($this->getRelativePath($file1), $orphanedFiles);
        $this->assertContains($this->getRelativePath($file2), $orphanedFiles);
        $this->assertContains($this->getRelativePath($file3), $orphanedFiles);
    }

    public function testFindOrphanedFilesIgnoresFilesWithDatabaseRecords(): void
    {
        // Create physical files
        $file1 = $this->createTestFile('2024/01/user-123/test1.pdf');
        $file2 = $this->createTestFile('2024/01/user-123/test2.pdf');
        $file3 = $this->createTestFile('2024/02/user-456/test3.pdf');

        // Create mock documents for file1 and file3
        $doc1 = $this->createMockDocument('2024/01/user-123/test1.pdf');
        $doc3 = $this->createMockDocument('2024/02/user-456/test3.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([$doc1, $doc3]);

        $orphanedFiles = $this->cleanupService->findOrphanedFiles();

        // Only file2 should be orphaned
        $this->assertCount(1, $orphanedFiles);
        $this->assertContains($this->getRelativePath($file2), $orphanedFiles);
    }

    public function testFindMissingFilesReturnsEmptyWhenAllFilesExist(): void
    {
        // Create physical file
        $file1 = $this->createTestFile('2024/01/user-123/test1.pdf');

        // Create corresponding document
        $doc1 = $this->createMockDocument('2024/01/user-123/test1.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([$doc1]);

        $missingFiles = $this->cleanupService->findMissingFiles();

        $this->assertIsArray($missingFiles);
        $this->assertCount(0, $missingFiles);
    }

    public function testFindMissingFilesDetectsDocumentsWithoutPhysicalFiles(): void
    {
        // Create physical file for doc1 only
        $this->createTestFile('2024/01/user-123/test1.pdf');

        // Create documents
        $doc1 = $this->createMockDocument('2024/01/user-123/test1.pdf');
        $doc2 = $this->createMockDocument('2024/01/user-123/test2.pdf'); // No physical file
        $doc3 = $this->createMockDocument('2024/02/user-456/test3.pdf'); // No physical file

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([$doc1, $doc2, $doc3]);

        $missingFiles = $this->cleanupService->findMissingFiles();

        $this->assertCount(2, $missingFiles);
        $this->assertArrayHasKey('2024/01/user-123/test2.pdf', $missingFiles);
        $this->assertArrayHasKey('2024/02/user-456/test3.pdf', $missingFiles);
    }

    public function testCleanupOrphanedFilesInDryRunMode(): void
    {
        // Create orphaned files
        $file1 = $this->createTestFile('2024/01/user-123/orphan1.pdf');
        $file2 = $this->createTestFile('2024/01/user-123/orphan2.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        // Dry run - should not delete files
        $result = $this->cleanupService->cleanupOrphanedFiles(dryRun: true);

        $this->assertArrayHasKey('orphanedFiles', $result);
        $this->assertArrayHasKey('filesDeleted', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('dryRun', $result);

        $this->assertTrue($result['dryRun']);
        $this->assertCount(2, $result['orphanedFiles']);
        $this->assertEquals(0, $result['filesDeleted']);

        // Verify files still exist
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);
    }

    public function testCleanupOrphanedFilesDeletesFilesWhenNotDryRun(): void
    {
        // Create orphaned files
        $file1 = $this->createTestFile('2024/01/user-123/orphan1.pdf');
        $file2 = $this->createTestFile('2024/01/user-123/orphan2.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        // Real cleanup - should delete files
        $result = $this->cleanupService->cleanupOrphanedFiles(dryRun: false);

        $this->assertFalse($result['dryRun']);
        $this->assertCount(2, $result['orphanedFiles']);
        $this->assertEquals(2, $result['filesDeleted']);
        $this->assertCount(0, $result['errors']);

        // Verify files are deleted
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
    }

    public function testCleanupOrphanedFilesHandlesDeleteErrors(): void
    {
        // Create a file that can't be deleted (e.g., in a read-only directory)
        $readOnlyDir = $this->testBasePath . '/2024/01/user-123';
        mkdir($readOnlyDir, 0755, true);

        $file = $readOnlyDir . '/test.pdf';
        file_put_contents($file, 'test content');

        // Make directory read-only (file can't be deleted)
        chmod($readOnlyDir, 0555);

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        $result = $this->cleanupService->cleanupOrphanedFiles(dryRun: false);

        // Cleanup: restore permissions before assertion
        chmod($readOnlyDir, 0755);

        // Should report error
        $this->assertGreaterThan(0, count($result['errors']));
    }

    public function testCleanupOrphanedFilesOnlyDeletesOrphanedFiles(): void
    {
        // Create mix of orphaned and valid files
        $orphan1 = $this->createTestFile('2024/01/user-123/orphan1.pdf');
        $valid1 = $this->createTestFile('2024/01/user-123/valid1.pdf');
        $orphan2 = $this->createTestFile('2024/02/user-456/orphan2.pdf');

        // Create document for valid file
        $doc1 = $this->createMockDocument('2024/01/user-123/valid1.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([$doc1]);

        $result = $this->cleanupService->cleanupOrphanedFiles(dryRun: false);

        // Should delete 2 orphaned files
        $this->assertEquals(2, $result['filesDeleted']);
        $this->assertFileDoesNotExist($orphan1);
        $this->assertFileDoesNotExist($orphan2);

        // Should preserve valid file
        $this->assertFileExists($valid1);
    }

    public function testGetCleanupStatisticsReturnsAccurateData(): void
    {
        // Create files
        $orphan1 = $this->createTestFile('2024/01/user-123/orphan1.pdf');
        $orphan2 = $this->createTestFile('2024/01/user-123/orphan2.pdf');
        $valid1 = $this->createTestFile('2024/01/user-123/valid1.pdf');

        // Create documents
        $doc1 = $this->createMockDocument('2024/01/user-123/valid1.pdf');
        $doc2 = $this->createMockDocument('2024/01/user-123/missing.pdf'); // No physical file

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([$doc1, $doc2]);

        $stats = $this->cleanupService->getCleanupStatistics();

        $this->assertArrayHasKey('totalFilesOnDisk', $stats);
        $this->assertArrayHasKey('totalDocumentsInDatabase', $stats);
        $this->assertArrayHasKey('orphanedFilesCount', $stats);
        $this->assertArrayHasKey('missingFilesCount', $stats);

        $this->assertEquals(3, $stats['totalFilesOnDisk']);
        $this->assertEquals(2, $stats['totalDocumentsInDatabase']);
        $this->assertEquals(2, $stats['orphanedFilesCount']);
        $this->assertEquals(1, $stats['missingFilesCount']);
    }

    public function testCleanupIgnoresNonDocumentFiles(): void
    {
        // Create various files including non-document files
        $this->createTestFile('2024/01/user-123/document.pdf');
        $this->createTestFile('2024/01/user-123/.hidden');
        $this->createTestFile('2024/01/user-123/Thumbs.db');
        $this->createTestFile('2024/01/user-123/.DS_Store');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        $orphanedFiles = $this->cleanupService->findOrphanedFiles();

        // Should only find the PDF file
        $this->assertCount(1, $orphanedFiles);
        $this->assertStringContainsString('document.pdf', $orphanedFiles[0]);
    }

    public function testCleanupHandlesNestedDirectoryStructure(): void
    {
        // Create files in various nested directories
        $this->createTestFile('2024/01/user-123/doc1.pdf');
        $this->createTestFile('2024/01/user-456/doc2.pdf');
        $this->createTestFile('2024/02/user-123/doc3.pdf');
        $this->createTestFile('2024/02/user-789/doc4.pdf');
        $this->createTestFile('2025/01/user-123/doc5.pdf');

        $repository = $this->entityManager->getRepository(Document::class);
        $repository
            ->method('findAll')
            ->willReturn([]);

        $orphanedFiles = $this->cleanupService->findOrphanedFiles();

        // Should find all 5 files
        $this->assertCount(5, $orphanedFiles);
    }

    /**
     * Helper: Create a test file at relative path
     */
    private function createTestFile(string $relativePath): string
    {
        $fullPath = $this->testBasePath . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, 'test content');

        return $fullPath;
    }

    /**
     * Helper: Get relative path from full path
     */
    private function getRelativePath(string $fullPath): string
    {
        return str_replace($this->testBasePath . '/', '', $fullPath);
    }

    /**
     * Helper: Create a mock document
     */
    private function createMockDocument(string $relativePath): Document
    {
        $document = $this->createMock(Document::class);
        $document->method('getFilePath')->willReturn($relativePath);
        return $document;
    }

    /**
     * Helper: Recursively remove directory
     */
    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                // Ensure directory is writable before trying to delete
                chmod($path, 0755);
                $this->recursiveRemoveDirectory($path);
            } else {
                // Ensure file is writable before trying to delete
                chmod($path, 0644);
                unlink($path);
            }
        }

        chmod($directory, 0755);
        rmdir($directory);
    }
}
