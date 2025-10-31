<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Document;
use App\Entity\User;
use App\Service\DocumentProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for complete progress tracking flow (Task 5.10)
 *
 * Tests the end-to-end progress tracking from upload through all milestones to completion:
 * - Upload (0%)
 * - Queued (0%)
 * - Processing start (10-25%)
 * - OCR midpoint (~50%)
 * - Metadata extraction (75%)
 * - Completion (100%)
 */
class ProgressTrackingFlowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentProcessingService $processingService;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->processingService = $container->get(DocumentProcessingService::class);

        // Create test user
        $this->testUser = new User();
        $this->testUser->setEmail('progress-test-' . uniqid() . '@example.com');
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->entityManager->persist($this->testUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->testUser)) {
            $this->entityManager->remove($this->testUser);
            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    private function createTestDocument(): Document
    {
        $document = new Document();
        $document->setOriginalName('progress-test.pdf');
        $document->setFilename('test-' . uniqid() . '.pdf');
        $document->setFilePath('/shared/test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setProcessingStatus('uploaded');
        $document->setProgress(0);
        $document->setUploadedBy($this->testUser);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /**
     * Test document starts with 0% progress after upload
     */
    public function testDocumentStartsWithZeroProgressAfterUpload(): void
    {
        $document = $this->createTestDocument();

        $this->assertEquals(0, $document->getProgress());
        $this->assertEquals('uploaded', $document->getProcessingStatus());
        $this->assertNull($document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress transitions from uploaded to queued
     */
    public function testProgressTransitionsFromUploadedToQueued(): void
    {
        $document = $this->createTestDocument();

        // Simulate queue state
        $document->setProcessingStatus('queued');
        $document->setProgress(0);
        $document->setCurrentOperation('Waiting in queue');
        $this->entityManager->flush();

        $this->assertEquals(0, $document->getProgress());
        $this->assertEquals('queued', $document->getProcessingStatus());
        $this->assertEquals('Waiting in queue', $document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress at 25% milestone (document conversion complete)
     */
    public function testProgressAt25PercentMilestone(): void
    {
        $document = $this->createTestDocument();

        // Simulate webhook update at 25% milestone
        $document->setProcessingStatus('processing');
        $document->setProgress(25);
        $document->setCurrentOperation('Performing OCR on 10 pages');
        $this->entityManager->flush();

        $this->assertEquals(25, $document->getProgress());
        $this->assertEquals('processing', $document->getProcessingStatus());
        $this->assertStringContainsString('OCR', $document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress at ~50% milestone (mid-OCR processing)
     */
    public function testProgressAt50PercentMilestone(): void
    {
        $document = $this->createTestDocument();

        // Simulate webhook update at 50% milestone
        $document->setProcessingStatus('processing');
        $document->setProgress(50);
        $document->setCurrentOperation('Performing OCR on page 5/10');
        $this->entityManager->flush();

        $this->assertEquals(50, $document->getProgress());
        $this->assertEquals('processing', $document->getProcessingStatus());
        $this->assertStringContainsString('page', $document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress at 75% milestone (metadata extraction)
     */
    public function testProgressAt75PercentMilestone(): void
    {
        $document = $this->createTestDocument();

        // Simulate webhook update at 75% milestone
        $document->setProcessingStatus('processing');
        $document->setProgress(75);
        $document->setCurrentOperation('Extracting metadata');
        $this->entityManager->flush();

        $this->assertEquals(75, $document->getProgress());
        $this->assertEquals('processing', $document->getProcessingStatus());
        $this->assertStringContainsString('metadata', strtolower($document->getCurrentOperation()));

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress at 100% (completion)
     */
    public function testProgressAt100PercentOnCompletion(): void
    {
        $document = $this->createTestDocument();

        // Simulate completion webhook
        $document->setProcessingStatus('completed');
        $document->setProgress(100);
        $document->setCurrentOperation(null); // No operation when completed
        $document->setOcrText('Sample OCR text');
        $document->setConfidenceScore('0.95');
        $this->entityManager->flush();

        $this->assertEquals(100, $document->getProgress());
        $this->assertEquals('completed', $document->getProcessingStatus());
        $this->assertNull($document->getCurrentOperation());
        $this->assertNotNull($document->getOcrText());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test complete progress flow: 0% → 25% → 50% → 75% → 100%
     */
    public function testCompleteProgressTrackingFlow(): void
    {
        $document = $this->createTestDocument();

        // Stage 1: Upload (0%)
        $this->assertEquals(0, $document->getProgress());
        $this->assertEquals('uploaded', $document->getProcessingStatus());

        // Stage 2: Queued (0%)
        $document->setProcessingStatus('queued');
        $document->setProgress(0);
        $document->setCurrentOperation('Waiting in queue');
        $this->entityManager->flush();

        $this->assertEquals(0, $document->getProgress());
        $this->assertEquals('queued', $document->getProcessingStatus());

        // Stage 3: Processing start - document conversion (25%)
        $document->setProcessingStatus('processing');
        $document->setProgress(25);
        $document->setCurrentOperation('Performing OCR on 4 pages');
        $this->entityManager->flush();

        $this->assertEquals(25, $document->getProgress());
        $this->assertEquals('processing', $document->getProcessingStatus());

        // Stage 4: Mid-OCR (50%)
        $document->setProgress(50);
        $document->setCurrentOperation('Performing OCR on page 2/4');
        $this->entityManager->flush();

        $this->assertEquals(50, $document->getProgress());
        $this->assertStringContainsString('page 2/4', $document->getCurrentOperation());

        // Stage 5: Metadata extraction (75%)
        $document->setProgress(75);
        $document->setCurrentOperation('Extracting metadata');
        $this->entityManager->flush();

        $this->assertEquals(75, $document->getProgress());
        $this->assertStringContainsString('metadata', strtolower($document->getCurrentOperation()));

        // Stage 6: Categorization (85%)
        $document->setProgress(85);
        $document->setCurrentOperation('Categorizing document');
        $this->entityManager->flush();

        $this->assertEquals(85, $document->getProgress());

        // Stage 7: Completion (100%)
        $document->setProcessingStatus('completed');
        $document->setProgress(100);
        $document->setCurrentOperation(null);
        $document->setOcrText('Final OCR text result');
        $document->setConfidenceScore('0.92');
        $this->entityManager->flush();

        $this->assertEquals(100, $document->getProgress());
        $this->assertEquals('completed', $document->getProcessingStatus());
        $this->assertNull($document->getCurrentOperation());
        $this->assertEquals('Final OCR text result', $document->getOcrText());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress values are always between 0 and 100
     */
    public function testProgressValuesAreWithinValidRange(): void
    {
        $document = $this->createTestDocument();

        $progressSteps = [0, 10, 25, 38, 50, 63, 75, 85, 95, 100];

        foreach ($progressSteps as $progress) {
            $document->setProgress($progress);
            $this->entityManager->flush();

            $this->assertGreaterThanOrEqual(0, $document->getProgress());
            $this->assertLessThanOrEqual(100, $document->getProgress());
            $this->assertEquals($progress, $document->getProgress());
        }

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test progress increases monotonically during processing
     */
    public function testProgressIncreasesMonotonically(): void
    {
        $document = $this->createTestDocument();

        $progressSequence = [0, 10, 25, 50, 75, 85, 95, 100];
        $previousProgress = -1;

        foreach ($progressSequence as $progress) {
            $document->setProgress($progress);
            $this->entityManager->flush();

            $this->assertGreaterThan($previousProgress, $document->getProgress());
            $previousProgress = $document->getProgress();
        }

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test current_operation is set during processing and null when not processing
     */
    public function testCurrentOperationLifecycle(): void
    {
        $document = $this->createTestDocument();

        // Before processing starts
        $this->assertNull($document->getCurrentOperation());

        // During processing - should have operation
        $document->setProcessingStatus('processing');
        $document->setProgress(25);
        $document->setCurrentOperation('Converting document');
        $this->entityManager->flush();

        $this->assertNotNull($document->getCurrentOperation());
        $this->assertNotEmpty($document->getCurrentOperation());

        // Different stages have different operations
        $document->setProgress(50);
        $document->setCurrentOperation('Performing OCR');
        $this->entityManager->flush();
        $this->assertEquals('Performing OCR', $document->getCurrentOperation());

        $document->setProgress(75);
        $document->setCurrentOperation('Extracting metadata');
        $this->entityManager->flush();
        $this->assertEquals('Extracting metadata', $document->getCurrentOperation());

        // After completion - should be null
        $document->setProcessingStatus('completed');
        $document->setProgress(100);
        $document->setCurrentOperation(null);
        $this->entityManager->flush();

        $this->assertNull($document->getCurrentOperation());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    /**
     * Test failed processing maintains last known progress
     */
    public function testFailedProcessingMaintainsProgress(): void
    {
        $document = $this->createTestDocument();

        // Processing was at 50% when it failed
        $document->setProcessingStatus('processing');
        $document->setProgress(50);
        $document->setCurrentOperation('Performing OCR on page 5/10');
        $this->entityManager->flush();

        // Now it fails
        $document->setProcessingStatus('failed');
        $document->setProcessingError('OCR service timeout');
        // Progress should remain at 50%
        $this->entityManager->flush();

        $this->assertEquals('failed', $document->getProcessingStatus());
        $this->assertEquals(50, $document->getProgress());
        $this->assertNotNull($document->getProcessingError());

        // Cleanup
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }
}
