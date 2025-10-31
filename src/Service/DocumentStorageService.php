<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Document Storage Service
 *
 * Manages organized directory structure for document storage
 * Structure: /YYYY/MM/user-{user-id}/{unique-filename}
 *
 * Benefits:
 * - Prevents single directory from having too many files
 * - Easy to locate files by date and user
 * - Improves filesystem performance
 * - Simplifies backup and archival operations
 */
class DocumentStorageService
{
    public function __construct(
        private readonly string $basePath
    ) {
    }

    /**
     * Generate organized storage path for a user
     *
     * Returns path in format: /base/YYYY/MM/user-{id}/
     * Creates directory structure if it doesn't exist
     */
    public function generateStoragePath(User $user, ?\DateTimeInterface $date = null): string
    {
        $date = $date ?? new \DateTimeImmutable();

        $year = $date->format('Y');
        $month = $date->format('m');
        $userId = $user->getId();

        $path = sprintf(
            '%s/%s/%s/user-%s/',
            rtrim($this->basePath, '/'),
            $year,
            $month,
            $userId
        );

        $this->ensureDirectoryExists($path);

        return $path;
    }

    /**
     * Generate complete file path with unique filename
     *
     * Combines storage path with sanitized unique filename
     */
    public function generateFilePath(User $user, string $originalFilename, ?\DateTimeInterface $date = null): string
    {
        $storagePath = $this->generateStoragePath($user, $date);
        $sanitizedFilename = $this->sanitizeFilename($originalFilename);
        $uniqueFilename = $this->generateUniqueFilename($sanitizedFilename);

        return $storagePath . $uniqueFilename;
    }

    /**
     * Get relative path (without base path)
     *
     * Useful for storing in database
     */
    public function getRelativePath(string $absolutePath): string
    {
        $basePath = rtrim($this->basePath, '/') . '/';

        if (str_starts_with($absolutePath, $basePath)) {
            return substr($absolutePath, strlen($basePath));
        }

        return $absolutePath;
    }

    /**
     * Get absolute path from relative path
     *
     * Converts database path to filesystem path
     */
    public function getAbsolutePath(string $relativePath): string
    {
        return rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Sanitize filename to prevent security issues
     *
     * - Removes path traversal attempts
     * - Removes special characters
     * - Preserves file extension
     */
    private function sanitizeFilename(string $filename): string
    {
        // Get file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Remove path components
        $basename = basename($basename);

        // Remove any characters that could cause issues
        $basename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $basename);

        // Remove multiple underscores
        $basename = preg_replace('/_+/', '_', $basename);

        // Trim underscores from start and end
        $basename = trim($basename, '_');

        // Ensure filename is not empty
        if (empty($basename)) {
            $basename = 'unnamed_file';
        }

        // Add extension back if it exists
        if (!empty($extension)) {
            return $basename . '.' . strtolower($extension);
        }

        return $basename;
    }

    /**
     * Generate unique filename to prevent collisions
     *
     * Adds timestamp and random component to ensure uniqueness
     */
    private function generateUniqueFilename(string $sanitizedFilename): string
    {
        $extension = pathinfo($sanitizedFilename, PATHINFO_EXTENSION);
        $basename = pathinfo($sanitizedFilename, PATHINFO_FILENAME);

        // Create unique prefix with timestamp and random string
        $uniqueId = uniqid('doc_', true);

        // Combine parts
        $uniqueFilename = sprintf(
            '%s_%s.%s',
            $uniqueId,
            $basename,
            $extension
        );

        return $uniqueFilename;
    }

    /**
     * Ensure directory exists and is writable
     *
     * Creates nested directories with proper permissions
     */
    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new \RuntimeException(sprintf('Unable to create directory: %s', $path));
            }
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(sprintf('Directory is not writable: %s', $path));
        }
    }

    /**
     * Get base storage path
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
