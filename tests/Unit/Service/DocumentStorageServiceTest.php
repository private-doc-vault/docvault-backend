<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\DocumentStorageService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DocumentStorageService
 *
 * Tests cover:
 * - Organized directory path generation (YYYY/MM/user-{id}/)
 * - Path sanitization and validation
 * - Directory creation
 * - File path generation
 */
class DocumentStorageServiceTest extends TestCase
{
    private DocumentStorageService $storageService;
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = '/tmp/test-storage';
        $this->storageService = new DocumentStorageService($this->basePath);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->basePath)) {
            $this->recursiveRemoveDirectory($this->basePath);
        }
    }

    public function testGenerateStoragePathCreatesDateBasedStructure(): void
    {
        $user = $this->createMockUser('user-123');

        $path = $this->storageService->generateStoragePath($user);

        // Should follow pattern: /YYYY/MM/user-{id}/
        $expectedPattern = '#^' . preg_quote($this->basePath, '#') . '/\d{4}/\d{2}/user-.+/$#';
        $this->assertMatchesRegularExpression($expectedPattern, $path);
    }

    public function testGenerateStoragePathIncludesCurrentYearAndMonth(): void
    {
        $user = $this->createMockUser('user-123');

        $path = $this->storageService->generateStoragePath($user);

        $currentYear = date('Y');
        $currentMonth = date('m');

        $this->assertStringContainsString("/{$currentYear}/{$currentMonth}/", $path);
    }

    public function testGenerateStoragePathIncludesUserId(): void
    {
        $userId = 'abc123-def456';
        $user = $this->createMockUser($userId);

        $path = $this->storageService->generateStoragePath($user);

        $this->assertStringContainsString("/user-{$userId}/", $path);
    }

    public function testGenerateStoragePathCreatesDirectoryIfNotExists(): void
    {
        $user = $this->createMockUser('user-123');

        $path = $this->storageService->generateStoragePath($user);

        $this->assertDirectoryExists($path);
        $this->assertTrue(is_writable($path));
    }

    public function testGenerateFilePathCreatesUniqueFilename(): void
    {
        $user = $this->createMockUser('user-123');
        $originalFilename = 'test-document.pdf';

        $filePath1 = $this->storageService->generateFilePath($user, $originalFilename);
        $filePath2 = $this->storageService->generateFilePath($user, $originalFilename);

        // Two calls should generate different filenames
        $this->assertNotEquals($filePath1, $filePath2);
    }

    public function testGenerateFilePathPreservesFileExtension(): void
    {
        $user = $this->createMockUser('user-123');

        $pdfPath = $this->storageService->generateFilePath($user, 'document.pdf');
        $jpgPath = $this->storageService->generateFilePath($user, 'scan.jpg');
        $pngPath = $this->storageService->generateFilePath($user, 'image.png');

        $this->assertStringEndsWith('.pdf', $pdfPath);
        $this->assertStringEndsWith('.jpg', $jpgPath);
        $this->assertStringEndsWith('.png', $pngPath);
    }

    public function testGenerateFilePathSanitizesFilename(): void
    {
        $user = $this->createMockUser('user-123');

        $dangerousFilename = '../../etc/passwd.pdf';
        $filePath = $this->storageService->generateFilePath($user, $dangerousFilename);

        // Should not contain path traversal
        $this->assertStringNotContainsString('..', $filePath);
        $this->assertStringNotContainsString('/etc/', $filePath);
    }

    public function testGenerateFilePathHandlesSpecialCharacters(): void
    {
        $user = $this->createMockUser('user-123');

        $filename = 'document with spaces & special!@#chars.pdf';
        $filePath = $this->storageService->generateFilePath($user, $filename);

        // Should sanitize special characters - filename includes unique prefix with dots
        $basename = basename($filePath);
        $this->assertMatchesRegularExpression('/^doc_[\da-f\.]+_[a-zA-Z0-9_]+\.pdf$/', $basename);
        $this->assertStringNotContainsString(' ', $basename);
        $this->assertStringNotContainsString('&', $basename);
        $this->assertStringNotContainsString('!', $basename);
    }

    public function testGetRelativePathReturnsPathWithoutBasePath(): void
    {
        $user = $this->createMockUser('user-123');
        $filePath = $this->storageService->generateFilePath($user, 'document.pdf');

        $relativePath = $this->storageService->getRelativePath($filePath);

        $this->assertStringStartsNotWith($this->basePath, $relativePath);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/user-.+/doc_[\da-f\.]+_.+\.pdf$#', $relativePath);
    }

    public function testGetAbsolutePathConvertsRelativeToAbsolute(): void
    {
        $relativePath = '2025/10/user-123/document.pdf';

        $absolutePath = $this->storageService->getAbsolutePath($relativePath);

        $this->assertEquals($this->basePath . '/' . $relativePath, $absolutePath);
    }

    public function testEnsureDirectoryExistsCreatesNestedDirectories(): void
    {
        $user = $this->createMockUser('user-new-dir');

        // Generate path which should create directories
        $path = $this->storageService->generateStoragePath($user);

        $this->assertDirectoryExists($path);

        // Verify parent directories exist
        $year = date('Y');
        $month = date('m');
        $this->assertDirectoryExists("{$this->basePath}/{$year}");
        $this->assertDirectoryExists("{$this->basePath}/{$year}/{$month}");
    }

    public function testGenerateStoragePathForSameUserReturnsSameDirectory(): void
    {
        $user = $this->createMockUser('user-same');

        $path1 = $this->storageService->generateStoragePath($user);
        $path2 = $this->storageService->generateStoragePath($user);

        // Same user should get same directory path (on same date)
        $this->assertEquals($path1, $path2);
    }

    public function testGenerateFilePathForDifferentUsersUsesDifferentDirectories(): void
    {
        $user1 = $this->createMockUser('user-1');
        $user2 = $this->createMockUser('user-2');

        $path1 = $this->storageService->generateFilePath($user1, 'document.pdf');
        $path2 = $this->storageService->generateFilePath($user2, 'document.pdf');

        // Different users should have different paths
        $this->assertStringContainsString('user-user-1', $path1);
        $this->assertStringContainsString('user-user-2', $path2);
        $this->assertNotEquals(dirname($path1), dirname($path2));
    }

    /**
     * Create a mock user with specified ID
     */
    private function createMockUser(string $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        return $user;
    }

    /**
     * Recursively remove a directory
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
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
