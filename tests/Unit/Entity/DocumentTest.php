<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use PHPUnit\Framework\TestCase;
use Doctrine\Common\Collections\ArrayCollection;

class DocumentTest extends TestCase
{
    private Document $document;

    protected function setUp(): void
    {
        $this->document = new Document();
    }

    public function testDocumentCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Document::class, $this->document);
    }

    public function testDocumentHasUuidId(): void
    {
        $this->assertNull($this->document->getId());
        
        // Test that ID can be set (for testing purposes)
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->document->setId($uuid);
        $this->assertEquals($uuid, $this->document->getId());
    }

    public function testDocumentFilename(): void
    {
        $filename = 'test-document.pdf';
        $this->document->setFilename($filename);
        
        $this->assertEquals($filename, $this->document->getFilename());
    }

    public function testDocumentOriginalName(): void
    {
        $originalName = 'My Important Document.pdf';
        $this->document->setOriginalName($originalName);
        
        $this->assertEquals($originalName, $this->document->getOriginalName());
    }

    public function testDocumentMimeType(): void
    {
        $mimeType = 'application/pdf';
        $this->document->setMimeType($mimeType);
        
        $this->assertEquals($mimeType, $this->document->getMimeType());
    }

    public function testDocumentFileSize(): void
    {
        $fileSize = 1048576; // 1MB in bytes
        $this->document->setFileSize($fileSize);
        
        $this->assertEquals($fileSize, $this->document->getFileSize());
    }

    public function testDocumentFilePath(): void
    {
        $filePath = '/storage/documents/2024/01/test-document.pdf';
        $this->document->setFilePath($filePath);
        
        $this->assertEquals($filePath, $this->document->getFilePath());
    }

    public function testDocumentOcrText(): void
    {
        $ocrText = 'This is the extracted text from OCR processing';
        $this->document->setOcrText($ocrText);
        
        $this->assertEquals($ocrText, $this->document->getOcrText());
    }

    public function testDocumentMetadata(): void
    {
        $metadata = [
            'extractedDate' => '2024-01-15',
            'amount' => '1000.00',
            'vendor' => 'ACME Corp',
            'documentType' => 'invoice'
        ];
        $this->document->setMetadata($metadata);
        
        $this->assertEquals($metadata, $this->document->getMetadata());
        $this->assertEquals('2024-01-15', $this->document->getMetadata()['extractedDate']);
    }

    public function testDocumentProcessingStatus(): void
    {
        // Test default status (should be QUEUED per docs/architecture/status-enumeration.md)
        $this->assertEquals(Document::STATUS_QUEUED, $this->document->getProcessingStatus());

        // Test setting different statuses using constants
        $this->document->setProcessingStatus(Document::STATUS_PROCESSING);
        $this->assertEquals(Document::STATUS_PROCESSING, $this->document->getProcessingStatus());

        $this->document->setProcessingStatus(Document::STATUS_COMPLETED);
        $this->assertEquals(Document::STATUS_COMPLETED, $this->document->getProcessingStatus());

        $this->document->setProcessingStatus(Document::STATUS_FAILED);
        $this->assertEquals(Document::STATUS_FAILED, $this->document->getProcessingStatus());
    }

    public function testDocumentProcessingError(): void
    {
        $this->assertNull($this->document->getProcessingError());
        
        $error = 'OCR processing failed: unable to read document';
        $this->document->setProcessingError($error);
        
        $this->assertEquals($error, $this->document->getProcessingError());
    }

    public function testDocumentThumbnailPath(): void
    {
        $thumbnailPath = '/storage/thumbnails/test-document-thumb.jpg';
        $this->document->setThumbnailPath($thumbnailPath);
        
        $this->assertEquals($thumbnailPath, $this->document->getThumbnailPath());
    }

    public function testDocumentCreatedAt(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-15 10:30:00');
        $this->document->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->document->getCreatedAt());
        $this->assertEquals('2024-01-15 10:30:00', $this->document->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testDocumentUpdatedAt(): void
    {
        $updatedAt = new \DateTimeImmutable('2024-01-15 15:45:00');
        $this->document->setUpdatedAt($updatedAt);
        
        $this->assertEquals($updatedAt, $this->document->getUpdatedAt());
    }

    public function testDocumentTagsCollection(): void
    {
        // Test that tags are initialized as empty collection
        $this->assertInstanceOf(ArrayCollection::class, $this->document->getTags());
        $this->assertCount(0, $this->document->getTags());
    }

    public function testDocumentCategory(): void
    {
        // Test that category is initially null
        $this->assertNull($this->document->getCategory());
        
        $category = new \App\Entity\Category();
        $category->setName('Financial Documents');
        
        $this->document->setCategory($category);
        $this->assertEquals($category, $this->document->getCategory());
    }

    public function testDocumentVersionNumber(): void
    {
        // Test default version
        $this->assertEquals(1, $this->document->getVersionNumber());
        
        $this->document->setVersionNumber(2);
        $this->assertEquals(2, $this->document->getVersionNumber());
    }

    public function testDocumentIsArchived(): void
    {
        // Test default value
        $this->assertFalse($this->document->isArchived());
        
        $this->document->setArchived(true);
        $this->assertTrue($this->document->isArchived());
        
        $this->document->setArchived(false);
        $this->assertFalse($this->document->isArchived());
    }

    public function testDocumentExtractedDate(): void
    {
        $extractedDate = new \DateTimeImmutable('2024-01-10');
        $this->document->setExtractedDate($extractedDate);
        
        $this->assertEquals($extractedDate, $this->document->getExtractedDate());
        $this->assertEquals('2024-01-10', $this->document->getExtractedDate()->format('Y-m-d'));
    }

    public function testDocumentExtractedAmount(): void
    {
        $amount = '1500.75';
        $this->document->setExtractedAmount($amount);
        
        $this->assertEquals($amount, $this->document->getExtractedAmount());
    }

    public function testDocumentSearchableContent(): void
    {
        $searchableContent = 'invoice receipt payment due amount vendor name';
        $this->document->setSearchableContent($searchableContent);
        
        $this->assertEquals($searchableContent, $this->document->getSearchableContent());
    }

    public function testDocumentLanguage(): void
    {
        // Test default language
        $this->assertEquals('en', $this->document->getLanguage());
        
        $this->document->setLanguage('de');
        $this->assertEquals('de', $this->document->getLanguage());
    }

    public function testDocumentConfidenceScore(): void
    {
        $confidence = 0.95;
        $this->document->setConfidenceScore($confidence);
        
        $this->assertEquals($confidence, $this->document->getConfidenceScore());
    }

    public function testDocumentValidProcessingStatuses(): void
    {
        // Test all valid statuses using constants (per docs/architecture/status-enumeration.md)
        $validStatuses = [
            Document::STATUS_QUEUED,
            Document::STATUS_PROCESSING,
            Document::STATUS_COMPLETED,
            Document::STATUS_FAILED
        ];

        foreach ($validStatuses as $status) {
            $this->document->setProcessingStatus($status);
            $this->assertEquals($status, $this->document->getProcessingStatus());
        }
    }

    public function testDocumentMetadataCanBeNull(): void
    {
        $this->document->setMetadata(null);
        $this->assertNull($this->document->getMetadata());
    }

    public function testDocumentToString(): void
    {
        $this->document->setOriginalName('My Document.pdf');
        $this->assertEquals('My Document.pdf', (string) $this->document);
        
        // Test fallback to filename if no original name
        $this->document->setOriginalName(null);
        $this->document->setFilename('my-document.pdf');
        $this->assertEquals('my-document.pdf', (string) $this->document);
        
        // Test fallback to ID if no names
        $this->document->setFilename(null);
        $this->document->setId('test-uuid');
        $this->assertEquals('Document: test-uuid', (string) $this->document);
    }
}