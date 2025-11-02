<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests for flattened metadata structure
 *
 * Tests verify that metadata is stored in a flat structure without
 * unnecessary "extracted_metadata" nesting
 */
class DocumentMetadataFlatteningTest extends TestCase
{
    public function testDocumentMetadataIsStoredFlat(): void
    {
        // GIVEN a document with metadata
        $document = $this->createDocument();

        // WHEN we set flat metadata
        $metadata = [
            'ocr_task_id' => 'task-123',
            'dates' => ['2024-01-15', '2024-02-20'],
            'amounts' => [1234.56, 99.99],
            'invoice_numbers' => ['INV-001'],
            'names' => ['John Doe', 'ABC Company'],
            'emails' => ['john@example.com'],
            'tax_ids' => ['123-456-789'],
            'category' => [
                'primary_category' => 'invoice',
                'confidence' => 0.95
            ]
        ];

        $document->setMetadata($metadata);

        // THEN metadata should be stored flat (no "extracted_metadata" nesting)
        $storedMetadata = $document->getMetadata();
        $this->assertIsArray($storedMetadata);
        $this->assertArrayHasKey('dates', $storedMetadata);
        $this->assertArrayHasKey('amounts', $storedMetadata);
        $this->assertArrayHasKey('invoice_numbers', $storedMetadata);
        $this->assertArrayNotHasKey('extracted_metadata', $storedMetadata);
    }

    public function testFlatMetadataContainsDatesDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'dates' => ['2024-01-15', '2024-02-20'],
            'amounts' => [1234.56]
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN dates should be at top level
        $this->assertArrayHasKey('dates', $storedMetadata);
        $this->assertEquals(['2024-01-15', '2024-02-20'], $storedMetadata['dates']);
        $this->assertArrayNotHasKey('extracted_metadata', $storedMetadata);
    }

    public function testFlatMetadataContainsAmountsDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'amounts' => [1234.56, 99.99, 500.00]
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN amounts should be at top level
        $this->assertArrayHasKey('amounts', $storedMetadata);
        $this->assertEquals([1234.56, 99.99, 500.00], $storedMetadata['amounts']);
        $this->assertArrayNotHasKey('extracted_metadata', $storedMetadata);
    }

    public function testFlatMetadataContainsInvoiceNumbersDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'invoice_numbers' => ['INV-001', 'INV-002']
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN invoice numbers should be at top level
        $this->assertArrayHasKey('invoice_numbers', $storedMetadata);
        $this->assertEquals(['INV-001', 'INV-002'], $storedMetadata['invoice_numbers']);
        $this->assertArrayNotHasKey('extracted_metadata', $storedMetadata);
    }

    public function testFlatMetadataContainsNamesDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'names' => ['John Doe', 'Jane Smith', 'ABC Company']
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN names should be at top level
        $this->assertArrayHasKey('names', $storedMetadata);
        $this->assertEquals(['John Doe', 'Jane Smith', 'ABC Company'], $storedMetadata['names']);
    }

    public function testFlatMetadataContainsEmailsDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'emails' => ['john@example.com', 'jane@example.com']
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN emails should be at top level
        $this->assertArrayHasKey('emails', $storedMetadata);
        $this->assertEquals(['john@example.com', 'jane@example.com'], $storedMetadata['emails']);
    }

    public function testFlatMetadataContainsTaxIdsDirectly(): void
    {
        // GIVEN a document with flat metadata
        $document = $this->createDocument();
        $metadata = [
            'tax_ids' => ['123-456-789', '987-654-321']
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN tax IDs should be at top level
        $this->assertArrayHasKey('tax_ids', $storedMetadata);
        $this->assertEquals(['123-456-789', '987-654-321'], $storedMetadata['tax_ids']);
    }

    public function testFlatMetadataContainsCategoryDirectly(): void
    {
        // GIVEN a document with flat metadata including category
        $document = $this->createDocument();
        $metadata = [
            'category' => [
                'primary_category' => 'invoice',
                'confidence' => 0.95
            ]
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN category should be at top level
        $this->assertArrayHasKey('category', $storedMetadata);
        $this->assertEquals('invoice', $storedMetadata['category']['primary_category']);
        $this->assertEquals(0.95, $storedMetadata['category']['confidence']);
    }

    public function testFlatMetadataContainsOcrTaskIdDirectly(): void
    {
        // GIVEN a document with OCR task metadata
        $document = $this->createDocument();
        $metadata = [
            'ocr_task_id' => 'task-abc-123',
            'ocr_status' => Document::STATUS_COMPLETED,
            'queued_at' => '2024-10-22 10:30:00'
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN OCR metadata should be at top level
        $this->assertArrayHasKey('ocr_task_id', $storedMetadata);
        $this->assertEquals('task-abc-123', $storedMetadata['ocr_task_id']);
        $this->assertArrayHasKey('ocr_status', $storedMetadata);
        $this->assertArrayHasKey('queued_at', $storedMetadata);
    }

    public function testFlatMetadataAllowsEmptyArrays(): void
    {
        // GIVEN a document with empty arrays in metadata
        $document = $this->createDocument();
        $metadata = [
            'dates' => [],
            'amounts' => [],
            'invoice_numbers' => []
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN empty arrays should be preserved
        $this->assertArrayHasKey('dates', $storedMetadata);
        $this->assertEmpty($storedMetadata['dates']);
        $this->assertArrayHasKey('amounts', $storedMetadata);
        $this->assertEmpty($storedMetadata['amounts']);
    }

    public function testFlatMetadataCanBeNull(): void
    {
        // GIVEN a document with no metadata
        $document = $this->createDocument();

        // WHEN metadata is null
        $document->setMetadata(null);

        // THEN getMetadata returns null
        $this->assertNull($document->getMetadata());
    }

    public function testComplexFlatMetadataStructure(): void
    {
        // GIVEN a document with complete flat metadata
        $document = $this->createDocument();
        $metadata = [
            'ocr_task_id' => 'task-xyz-789',
            'ocr_status' => Document::STATUS_COMPLETED,
            'queued_at' => '2024-10-22 10:30:00',
            'progress' => 100,
            'dates' => ['2024-01-15', '2024-02-20'],
            'amounts' => [1234.56, 99.99],
            'invoice_numbers' => ['INV-001', 'INV-002'],
            'names' => ['John Doe', 'ABC Company'],
            'emails' => ['john@example.com'],
            'tax_ids' => ['123-456-789'],
            'phones' => ['+1-555-0123'],
            'addresses' => ['123 Main St, City, State 12345'],
            'category' => [
                'primary_category' => 'invoice',
                'confidence' => 0.95,
                'alternatives' => ['receipt', 'bill']
            ]
        ];

        $document->setMetadata($metadata);

        // WHEN we retrieve metadata
        $storedMetadata = $document->getMetadata();

        // THEN all fields should be at top level
        $this->assertIsArray($storedMetadata);
        $this->assertCount(13, $storedMetadata);
        $this->assertArrayHasKey('dates', $storedMetadata);
        $this->assertArrayHasKey('amounts', $storedMetadata);
        $this->assertArrayHasKey('invoice_numbers', $storedMetadata);
        $this->assertArrayHasKey('names', $storedMetadata);
        $this->assertArrayHasKey('emails', $storedMetadata);
        $this->assertArrayHasKey('tax_ids', $storedMetadata);
        $this->assertArrayHasKey('phones', $storedMetadata);
        $this->assertArrayHasKey('addresses', $storedMetadata);
        $this->assertArrayHasKey('category', $storedMetadata);
        $this->assertArrayNotHasKey('extracted_metadata', $storedMetadata);
    }

    private function createDocument(): Document
    {
        $document = new Document();
        $document->setId('test-doc-' . uniqid());
        $document->setFilename('test.pdf');
        $document->setOriginalName('test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/path.pdf');
        $document->setProcessingStatus(Document::STATUS_QUEUED);

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }
}
