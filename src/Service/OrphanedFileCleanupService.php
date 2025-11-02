<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Orphaned File Cleanup Service
 *
 * Manages detection and cleanup of orphaned files in the document storage system.
 *
 * Features:
 * - Find files on disk without database records (orphaned files)
 * - Find database records without physical files (missing files)
 * - Cleanup orphaned files with dry-run mode
 * - Comprehensive statistics and reporting
 * - Safe cleanup with error handling
 */
class OrphanedFileCleanupService
{
    // Files to ignore during cleanup
    private const IGNORED_FILES = [
        '.DS_Store',
        'Thumbs.db',
        '.gitkeep',
        '.htaccess',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorageService $storageService
    ) {
    }

    /**
     * Find all orphaned files (files without database records)
     *
     * @return array<string> Array of relative file paths
     */
    public function findOrphanedFiles(): array
    {
        // Get all files from filesystem
        $filesOnDisk = $this->getAllFilesOnDisk();

        // Get all file paths from database
        $filesInDatabase = $this->getAllFilesInDatabase();

        // Find orphaned files (on disk but not in database)
        $orphanedFiles = [];

        foreach ($filesOnDisk as $filePath) {
            if (!in_array($filePath, $filesInDatabase, true)) {
                $orphanedFiles[] = $filePath;
            }
        }

        return $orphanedFiles;
    }

    /**
     * Find all missing files (database records without physical files)
     *
     * @return array<string, string> Array of [file_path => document_id]
     */
    public function findMissingFiles(): array
    {
        $missingFiles = [];

        /** @var Document[] $documents */
        $documents = $this->entityManager->getRepository(Document::class)->findAll();

        foreach ($documents as $document) {
            $filePath = $document->getFilePath();

            if (!$filePath) {
                continue;
            }

            $absolutePath = $this->storageService->getAbsolutePath($filePath);

            if (!file_exists($absolutePath)) {
                $missingFiles[$filePath] = $document->getId();
            }
        }

        return $missingFiles;
    }

    /**
     * Cleanup orphaned files
     *
     * @param bool $dryRun If true, only reports what would be deleted without actually deleting
     * @return array{orphanedFiles: array<string>, filesDeleted: int, errors: array<string>, dryRun: bool}
     */
    public function cleanupOrphanedFiles(bool $dryRun = true): array
    {
        $orphanedFiles = $this->findOrphanedFiles();
        $filesDeleted = 0;
        $errors = [];

        if (!$dryRun) {
            foreach ($orphanedFiles as $relativePath) {
                try {
                    $absolutePath = $this->storageService->getAbsolutePath($relativePath);

                    if (file_exists($absolutePath) && is_file($absolutePath)) {
                        // Suppress warning as we handle errors properly
                        if (@unlink($absolutePath)) {
                            $filesDeleted++;
                        } else {
                            $errors[] = "Failed to delete file: {$relativePath}";
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = "Error deleting {$relativePath}: " . $e->getMessage();
                }
            }
        }

        return [
            'orphanedFiles' => $orphanedFiles,
            'filesDeleted' => $filesDeleted,
            'errors' => $errors,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * Get comprehensive cleanup statistics
     *
     * @return array{totalFilesOnDisk: int, totalDocumentsInDatabase: int, orphanedFilesCount: int, missingFilesCount: int}
     */
    public function getCleanupStatistics(): array
    {
        $filesOnDisk = $this->getAllFilesOnDisk();
        $filesInDatabase = $this->getAllFilesInDatabase();
        $orphanedFiles = $this->findOrphanedFiles();
        $missingFiles = $this->findMissingFiles();

        return [
            'totalFilesOnDisk' => count($filesOnDisk),
            'totalDocumentsInDatabase' => count($filesInDatabase),
            'orphanedFilesCount' => count($orphanedFiles),
            'missingFilesCount' => count($missingFiles),
        ];
    }

    /**
     * Get all files on disk
     *
     * @return array<string> Array of relative file paths
     */
    private function getAllFilesOnDisk(): array
    {
        $files = [];
        $basePath = $this->storageService->getBasePath();

        if (!is_dir($basePath)) {
            return [];
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && !$this->shouldIgnoreFile($file)) {
                    // Use getPathname() instead of getRealPath() to avoid symlink resolution
                    $absolutePath = $file->getPathname();
                    $relativePath = $this->storageService->getRelativePath($absolutePath);
                    $files[] = $relativePath;
                }
            }
        } catch (\Exception $e) {
            // Return empty array if directory cannot be read
            return [];
        }

        return $files;
    }

    /**
     * Get all file paths from database
     *
     * @return array<string> Array of relative file paths
     */
    private function getAllFilesInDatabase(): array
    {
        $files = [];

        /** @var Document[] $documents */
        $documents = $this->entityManager->getRepository(Document::class)->findAll();

        foreach ($documents as $document) {
            $filePath = $document->getFilePath();

            if ($filePath) {
                $files[] = $filePath;
            }
        }

        return $files;
    }

    /**
     * Check if a file should be ignored during cleanup
     */
    private function shouldIgnoreFile(SplFileInfo $file): bool
    {
        $filename = $file->getFilename();

        // Ignore hidden files
        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Ignore specific files
        if (in_array($filename, self::IGNORED_FILES, true)) {
            return true;
        }

        return false;
    }
}
