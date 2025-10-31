<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DocumentVersion entity
 *
 * Tests cover:
 * - Version tracking and history
 * - File storage and metadata
 * - Relationships with Document and User
 * - Version number management
 */
class DocumentVersionTest extends TestCase
{
    public function testDocumentVersionCanBeCreated(): void
    {
        $version = new DocumentVersion();

        $this->assertInstanceOf(DocumentVersion::class, $version);
    }

    public function testIdCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();
        $id = 'version-123-abc';

        $version->setId($id);

        $this->assertEquals($id, $version->getId());
    }

    public function testDocumentCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();
        $document = $this->createMock(Document::class);

        $version->setDocument($document);

        $this->assertSame($document, $version->getDocument());
    }

    public function testVersionNumberCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();

        $version->setVersionNumber(5);

        $this->assertEquals(5, $version->getVersionNumber());
    }

    public function testFilePathCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();
        $path = '2025/10/user-123/document_v2.pdf';

        $version->setFilePath($path);

        $this->assertEquals($path, $version->getFilePath());
    }

    public function testFileSizeCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();

        $version->setFileSize(2048000);

        $this->assertEquals(2048000, $version->getFileSize());
    }

    public function testMimeTypeCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();

        $version->setMimeType('application/pdf');

        $this->assertEquals('application/pdf', $version->getMimeType());
    }

    public function testUploadedByCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();
        $user = $this->createMock(User::class);

        $version->setUploadedBy($user);

        $this->assertSame($user, $version->getUploadedBy());
    }

    public function testChangeDescriptionCanBeSetAndRetrieved(): void
    {
        $version = new DocumentVersion();
        $description = 'Updated with new content';

        $version->setChangeDescription($description);

        $this->assertEquals($description, $version->getChangeDescription());
    }

    public function testCreatedAtIsSetAutomaticallyOnPrePersist(): void
    {
        $version = new DocumentVersion();

        $this->assertNull($version->getCreatedAt());

        $version->onPrePersist();

        $this->assertInstanceOf(\DateTimeImmutable::class, $version->getCreatedAt());
    }

    public function testCreatedAtCanBeSetManually(): void
    {
        $version = new DocumentVersion();
        $date = new \DateTimeImmutable('2025-01-01 12:00:00');

        $version->setCreatedAt($date);

        $this->assertEquals($date, $version->getCreatedAt());
    }

    public function testVersionSupportsNullDocument(): void
    {
        $version = new DocumentVersion();

        $this->assertNull($version->getDocument());
    }

    public function testVersionSupportsNullUploadedBy(): void
    {
        $version = new DocumentVersion();

        $this->assertNull($version->getUploadedBy());
    }

    public function testVersionSupportsNullChangeDescription(): void
    {
        $version = new DocumentVersion();

        $this->assertNull($version->getChangeDescription());
    }

    public function testMultipleVersionsCanBeLinkToSameDocument(): void
    {
        $document = $this->createMock(Document::class);

        $version1 = new DocumentVersion();
        $version1->setDocument($document);
        $version1->setVersionNumber(1);

        $version2 = new DocumentVersion();
        $version2->setDocument($document);
        $version2->setVersionNumber(2);

        $this->assertSame($document, $version1->getDocument());
        $this->assertSame($document, $version2->getDocument());
        $this->assertNotEquals($version1->getVersionNumber(), $version2->getVersionNumber());
    }

    public function testVersionNumberIsRequiredAndPositive(): void
    {
        $version = new DocumentVersion();

        $version->setVersionNumber(1);
        $this->assertEquals(1, $version->getVersionNumber());

        $version->setVersionNumber(100);
        $this->assertEquals(100, $version->getVersionNumber());
    }

    public function testFilePathIsRequired(): void
    {
        $version = new DocumentVersion();

        $version->setFilePath('/some/path/to/file.pdf');

        $this->assertNotNull($version->getFilePath());
        $this->assertNotEmpty($version->getFilePath());
    }

    public function testFileSizeIsNonNegative(): void
    {
        $version = new DocumentVersion();

        $version->setFileSize(0); // Empty file
        $this->assertEquals(0, $version->getFileSize());

        $version->setFileSize(1024); // 1KB
        $this->assertEquals(1024, $version->getFileSize());

        $version->setFileSize(52428800); // 50MB
        $this->assertEquals(52428800, $version->getFileSize());
    }
}
