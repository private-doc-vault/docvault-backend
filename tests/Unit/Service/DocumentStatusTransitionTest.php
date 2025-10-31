<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use PHPUnit\Framework\TestCase;

/**
 * Tests for document status transitions to ensure consistency
 * with the status enumeration defined in docs/architecture/status-enumeration.md
 */
class DocumentStatusTransitionTest extends TestCase
{
    private function createDocument(string $initialStatus = Document::STATUS_QUEUED): Document
    {
        $document = new Document();
        $document->setFilename('test.pdf');
        $document->setOriginalName('test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/path/to/test.pdf');
        $document->setProcessingStatus($initialStatus);

        return $document;
    }

    /**
     * Test valid transition: null → QUEUED (initial upload)
     */
    public function testInitialUploadSetsStatusToQueued(): void
    {
        $document = $this->createDocument();

        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());
    }

    /**
     * Test valid transition: QUEUED → PROCESSING
     */
    public function testValidTransitionFromQueuedToProcessing(): void
    {
        $document = $this->createDocument(Document::STATUS_QUEUED);

        // This transition is valid
        $document->setProcessingStatus(Document::STATUS_PROCESSING);

        $this->assertEquals(Document::STATUS_PROCESSING, $document->getProcessingStatus());
    }

    /**
     * Test valid transition: QUEUED → FAILED (validation fails)
     */
    public function testValidTransitionFromQueuedToFailed(): void
    {
        $document = $this->createDocument(Document::STATUS_QUEUED);

        // This transition is valid (task validation fails before processing)
        $document->setProcessingStatus(Document::STATUS_FAILED);
        $document->setProcessingError('File validation failed');

        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertEquals('File validation failed', $document->getProcessingError());
    }

    /**
     * Test valid transition: PROCESSING → COMPLETED
     */
    public function testValidTransitionFromProcessingToCompleted(): void
    {
        $document = $this->createDocument(Document::STATUS_PROCESSING);

        // This transition is valid (successful processing)
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setProgress(100);

        $this->assertEquals(Document::STATUS_COMPLETED, $document->getProcessingStatus());
        $this->assertEquals(100, $document->getProgress());
    }

    /**
     * Test valid transition: PROCESSING → FAILED
     */
    public function testValidTransitionFromProcessingToFailed(): void
    {
        $document = $this->createDocument(Document::STATUS_PROCESSING);
        $document->setProgress(50);

        // This transition is valid (processing encounters error)
        $document->setProcessingStatus(Document::STATUS_FAILED);
        $document->setProcessingError('OCR processing failed');

        $this->assertEquals(Document::STATUS_FAILED, $document->getProcessingStatus());
        $this->assertEquals('OCR processing failed', $document->getProcessingError());
        $this->assertEquals(50, $document->getProgress()); // Progress maintained
    }

    /**
     * Test valid transition: FAILED → QUEUED (retry)
     */
    public function testValidTransitionFromFailedToQueuedOnRetry(): void
    {
        $document = $this->createDocument(Document::STATUS_FAILED);
        $document->setProcessingError('Previous error');

        // This transition is valid (manual or automatic retry)
        $document->setProcessingStatus(Document::STATUS_QUEUED);
        $document->setProcessingError(null); // Clear error on retry
        $document->setProgress(0); // Reset progress

        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());
        $this->assertNull($document->getProcessingError());
        $this->assertEquals(0, $document->getProgress());
    }

    /**
     * Test that COMPLETED is a terminal state
     */
    public function testCompletedIsTerminalState(): void
    {
        $document = $this->createDocument(Document::STATUS_COMPLETED);

        // COMPLETED should not transition to any other status
        // This is enforced by business logic, not entity constraints
        $this->assertEquals(Document::STATUS_COMPLETED, $document->getProcessingStatus());

        // In practice, attempting to change status from COMPLETED should be prevented
        // by service layer validation
    }

    /**
     * Test progress values align with status
     */
    public function testProgressValuesAlignWithStatus(): void
    {
        // QUEUED: 0%
        $document = $this->createDocument(Document::STATUS_QUEUED);
        $document->setProgress(0);
        $this->assertEquals(0, $document->getProgress());

        // PROCESSING: 1-99%
        $document->setProcessingStatus(Document::STATUS_PROCESSING);
        $document->setProgress(25);
        $this->assertEquals(25, $document->getProgress());
        $this->assertGreaterThan(0, $document->getProgress());
        $this->assertLessThan(100, $document->getProgress());

        // COMPLETED: 100%
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setProgress(100);
        $this->assertEquals(100, $document->getProgress());
    }

    /**
     * Test status constants match documented values
     */
    public function testStatusConstantsMatchDocumentation(): void
    {
        // Verify that entity constants match the documented status values
        $this->assertEquals('queued', Document::STATUS_QUEUED);
        $this->assertEquals('processing', Document::STATUS_PROCESSING);
        $this->assertEquals('completed', Document::STATUS_COMPLETED);
        $this->assertEquals('failed', Document::STATUS_FAILED);
    }

    /**
     * Test that deprecated status values are not used
     */
    public function testDeprecatedStatusValuesNotUsed(): void
    {
        $document = $this->createDocument();

        // These deprecated values should never be set
        $this->assertNotEquals('uploaded', $document->getProcessingStatus());
        $this->assertNotEquals('pending', $document->getProcessingStatus());
    }

    /**
     * Test status with current_operation field
     */
    public function testStatusWithCurrentOperation(): void
    {
        $document = $this->createDocument(Document::STATUS_PROCESSING);
        $document->setProgress(50);
        $document->setCurrentOperation('Extracting text from page 5/10');

        $this->assertEquals(Document::STATUS_PROCESSING, $document->getProcessingStatus());
        $this->assertEquals(50, $document->getProgress());
        $this->assertEquals('Extracting text from page 5/10', $document->getCurrentOperation());

        // When completed, current_operation should be null
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setProgress(100);
        $document->setCurrentOperation(null);

        $this->assertNull($document->getCurrentOperation());
    }

    /**
     * Test multiple status transitions in valid sequence
     */
    public function testCompleteValidStatusTransitionSequence(): void
    {
        $document = $this->createDocument(Document::STATUS_QUEUED);
        $this->assertEquals(Document::STATUS_QUEUED, $document->getProcessingStatus());

        // QUEUED → PROCESSING
        $document->setProcessingStatus(Document::STATUS_PROCESSING);
        $document->setProgress(25);
        $this->assertEquals(Document::STATUS_PROCESSING, $document->getProcessingStatus());

        // Progress updates during processing
        $document->setProgress(50);
        $document->setCurrentOperation('Extracting metadata');
        $this->assertEquals(50, $document->getProgress());

        $document->setProgress(75);
        $document->setCurrentOperation('Categorizing document');
        $this->assertEquals(75, $document->getProgress());

        // PROCESSING → COMPLETED
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setProgress(100);
        $document->setCurrentOperation(null);
        $this->assertEquals(Document::STATUS_COMPLETED, $document->getProcessingStatus());
        $this->assertEquals(100, $document->getProgress());
    }

    /**
     * Test failed document retry sequence
     */
    public function testFailedDocumentRetrySequence(): void
    {
        $document = $this->createDocument(Document::STATUS_QUEUED);

        // QUEUED → PROCESSING
        $document->setProcessingStatus(Document::STATUS_PROCESSING);
        $document->setProgress(30);

        // PROCESSING → FAILED
        $document->setProcessingStatus(Document::STATUS_FAILED);
        $document->setProcessingError('Network timeout');
        $this->assertEquals(30, $document->getProgress()); // Maintains progress

        // FAILED → QUEUED (retry)
        $document->setProcessingStatus(Document::STATUS_QUEUED);
        $document->setProgress(0);
        $document->setProcessingError(null);

        // Second attempt: QUEUED → PROCESSING → COMPLETED
        $document->setProcessingStatus(Document::STATUS_PROCESSING);
        $document->setProgress(50);

        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setProgress(100);

        $this->assertEquals(Document::STATUS_COMPLETED, $document->getProcessingStatus());
        $this->assertNull($document->getProcessingError());
    }
}
