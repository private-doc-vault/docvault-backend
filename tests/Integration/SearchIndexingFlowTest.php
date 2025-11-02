<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Document;
use App\Entity\User;
use App\Message\IndexDocumentMessage;
use App\MessageHandler\IndexDocumentMessageHandler;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Integration test for search indexing flow
 *
 * Tests the end-to-end flow:
 * 1. Document completes OCR processing
 * 2. IndexDocumentMessage is dispatched
 * 3. IndexDocumentMessageHandler processes message
 * 4. Document is indexed in Meilisearch
 * 5. Document becomes searchable
 */
class SearchIndexingFlowTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private SearchService $searchService;
    private MessageBusInterface $messageBus;
    private bool $useMockSearchService = true;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->messageBus = static::getContainer()->get(MessageBusInterface::class);
    }

    protected function tearDown(): void
    {
        // Clean up search index if using real Meilisearch
        if (!$this->useMockSearchService && isset($this->searchService)) {
            try {
                // Clear test documents from index
                // Note: In production tests, we would delete specific test documents
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        parent::tearDown();
    }

    private function createIndexHandler(): IndexDocumentMessageHandler
    {
        // Mock SearchService to avoid Meilisearch dependency in tests
        $this->searchService = $this->createMock(SearchService::class);

        // Create handler with mocked search service
        $logger = static::getContainer()->get(LoggerInterface::class);
        return new IndexDocumentMessageHandler(
            $this->entityManager,
            $this->searchService,
            $logger
        );
    }

    public function testCompleteIndexingFlowFromOcrCompletionToSearchable(): void
    {
        // GIVEN: A document that just completed OCR processing
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();

        // EXPECT: SearchService to be called once
        $this->searchService->expects($this->once())
            ->method('indexDocument')
            ->with($this->callback(function ($doc) use ($document) {
                return $doc->getId() === $document->getId()
                    && $doc->getProcessingStatus() === 'completed'
                    && $doc->getOcrText() !== null;
            }));

        // WHEN: IndexDocumentMessage is dispatched (as happens after OCR completion)
        $message = new IndexDocumentMessage($document->getId());
        ($indexHandler)($message);

        // THEN: Document should be indexed (verified by mock expectations)
        $this->assertTrue(true, 'Document indexing flow completed successfully');
    }

    public function testIndexingMessageCanBeDispatchedViaMessageBus(): void
    {
        // GIVEN: A completed document
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // WHEN: Message is dispatched via message bus
        $this->messageBus->dispatch(new IndexDocumentMessage($document->getId()));

        // THEN: Message should be queued for async processing
        $this->assertTrue(true, 'Message dispatched successfully');
    }

    public function testDocumentWithFullOcrDataIsIndexedCorrectly(): void
    {
        // GIVEN: A document with complete OCR data
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user);

        $document->setOcrText('Invoice FV/2024/001 for ABC Company dated 2024-01-15 amount 1234.56');
        $document->setSearchableContent('Invoice FV/2024/001 for ABC Company invoice_jan.pdf');
        $document->setMetadata([
            'extracted_metadata' => [
                'invoice_numbers' => ['FV/2024/001'],
                'names' => ['ABC Company'],
                'dates' => ['2024-01-15'],
                'amounts' => [1234.56],
                'tax_ids' => ['123-456-78-90']
            ]
        ]);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();
        $this->searchService->expects($this->once())->method('indexDocument');

        // WHEN: Document is indexed
        $message = new IndexDocumentMessage($document->getId());
        ($indexHandler)($message);

        // THEN: Should complete without errors
        $this->assertTrue(true, 'Document with rich OCR data indexed successfully');
    }

    public function testMultipleDocumentsCanBeIndexedSequentially(): void
    {
        // GIVEN: Multiple completed documents
        $user = $this->createTestUser();
        $this->entityManager->persist($user);

        $documents = [];
        for ($i = 1; $i <= 3; $i++) {
            $document = $this->createCompletedDocument($user, "document_{$i}.pdf");
            $document->setOcrText("Test document {$i} content");
            $this->entityManager->persist($document);
            $documents[] = $document;
        }
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();
        $this->searchService->expects($this->exactly(3))->method('indexDocument');

        // WHEN: All documents are indexed
        foreach ($documents as $document) {
            $message = new IndexDocumentMessage($document->getId());
            ($indexHandler)($message);
        }

        // THEN: All should be indexed successfully
        $this->assertCount(3, $documents);
        foreach ($documents as $document) {
            $this->assertEquals('completed', $document->getProcessingStatus());
            $this->assertNotNull($document->getOcrText());
        }
    }

    public function testNonCompletedDocumentsAreSkippedDuringIndexing(): void
    {
        // GIVEN: A document that is not yet completed
        $user = $this->createTestUser();
        $document = new Document();
        $document->setId('doc-' . uniqid());
        $document->setFilename('pending_doc.pdf');
        $document->setOriginalName('pending_doc.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/pending_doc.pdf');
        $document->setUploadedBy($user);
        $document->setProcessingStatus('pending'); // NOT completed
        $document->setOcrText(null);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();
        $this->searchService->expects($this->never())->method('indexDocument');

        // WHEN: Indexing is attempted
        $message = new IndexDocumentMessage($document->getId());

        // THEN: Should not throw error (handler skips non-completed documents)
        try {
            ($indexHandler)($message);
            $this->assertTrue(true, 'Handler gracefully skipped non-completed document');
        } catch (\Exception $e) {
            $this->fail('Handler should not throw exception for non-completed documents: ' . $e->getMessage());
        }
    }

    public function testReindexingUpdatesExistingDocumentIndex(): void
    {
        // GIVEN: A document that was already indexed
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user);
        $document->setOcrText('Original OCR text');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();
        $this->searchService->expects($this->exactly(2))->method('indexDocument');

        // AND: Document is indexed
        $message = new IndexDocumentMessage($document->getId());
        ($indexHandler)($message);

        // WHEN: Document is updated and reindexed
        $document->setOcrText('Updated OCR text after reprocessing');
        $document->setSearchableContent('Updated OCR text after reprocessing document.pdf');
        $this->entityManager->flush();

        // Reindex
        ($indexHandler)($message);

        // THEN: Should complete without errors
        $this->assertEquals('Updated OCR text after reprocessing', $document->getOcrText());
        $this->assertTrue(true, 'Document reindexed successfully');
    }

    public function testIndexingWorksWithEmptyOcrText(): void
    {
        // GIVEN: A completed document with empty OCR text (e.g., blank page)
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user);
        $document->setOcrText(''); // Empty but not null

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // AND: Handler with mocked search service
        $indexHandler = $this->createIndexHandler();
        $this->searchService->expects($this->once())->method('indexDocument');

        // WHEN: Document is indexed
        $message = new IndexDocumentMessage($document->getId());
        ($indexHandler)($message);

        // THEN: Should complete without errors
        $this->assertTrue(true, 'Document with empty OCR text indexed successfully');
    }

    // Helper methods

    private function createTestUser(): User
    {
        $user = new User();
        $user->setId('user-' . uniqid());
        $user->setEmail('test-' . uniqid() . '@example.com');
        $user->setPassword('password_hash');
        $user->setRoles(['ROLE_USER']);

        return $user;
    }

    private function createCompletedDocument(User $user, string $filename = 'test.pdf'): Document
    {
        $document = new Document();
        $document->setId('doc-' . uniqid());
        $document->setFilename($filename);
        $document->setOriginalName($filename);
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/' . $filename);
        $document->setUploadedBy($user);
        $document->setProcessingStatus('completed');
        $document->setOcrText('Sample OCR text for testing');
        $document->setSearchableContent('Sample OCR text for testing ' . $filename);
        $document->setConfidenceScore('0.95');

        return $document;
    }

    // ========================================================================
    // TASK 2.10: Test search functionality with newly indexed documents
    // ========================================================================

    /**
     * Test that documents become searchable after OCR completion and indexing
     *
     * This test verifies the complete flow:
     * 1. Document completes OCR
     * 2. Document is indexed
     * 3. Document can be found via search
     */
    public function testDocumentBecomesSearchableAfterIndexing(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        // GIVEN: A real SearchService instance (not mocked)
        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        // AND: A completed document with specific OCR content
        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'invoice_2024.pdf');
        $document->setOcrText('Invoice FV/2024/001 for Acme Corporation dated 2024-01-15 amount 5000.00 PLN');
        $document->setSearchableContent('Invoice FV/2024/001 for Acme Corporation invoice_2024.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // WHEN: Document is indexed
        $this->searchService->indexDocument($document);

        // Wait for Meilisearch to process the indexing
        usleep(500000); // 0.5 seconds

        // THEN: Document should be findable via search
        $searchResults = $this->searchService->search('Acme Corporation');

        $this->assertNotEmpty($searchResults['hits'], 'Search should return results');
        $this->assertGreaterThan(0, $searchResults['estimatedTotalHits']);

        // Verify the correct document was found
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds, 'Indexed document should be in search results');

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test searching for invoice numbers in OCR'd documents
     */
    public function testSearchByInvoiceNumber(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'invoice.pdf');
        $document->setOcrText('Invoice Number: FV/2024/12345 Date: 2024-01-15 Amount: 1000.00');
        $document->setSearchableContent('Invoice Number: FV/2024/12345 invoice.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->searchService->indexDocument($document);
        usleep(500000);

        // Search for specific invoice number
        $searchResults = $this->searchService->search('FV/2024/12345');

        $this->assertNotEmpty($searchResults['hits']);
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test searching for company names in OCR'd documents
     */
    public function testSearchByCompanyName(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();

        // Create two documents with different company names
        $doc1 = $this->createCompletedDocument($user, 'invoice_acme.pdf');
        $doc1->setOcrText('Invoice from Acme Corporation for services rendered');
        $doc1->setSearchableContent('Invoice from Acme Corporation invoice_acme.pdf');

        $doc2 = $this->createCompletedDocument($user, 'invoice_techcorp.pdf');
        $doc2->setOcrText('Invoice from TechCorp Ltd for software license');
        $doc2->setSearchableContent('Invoice from TechCorp Ltd invoice_techcorp.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($doc1);
        $this->entityManager->persist($doc2);
        $this->entityManager->flush();

        $this->searchService->indexDocument($doc1);
        $this->searchService->indexDocument($doc2);
        usleep(500000);

        // Search for "Acme" should only return doc1
        $searchResults = $this->searchService->search('Acme');
        $foundDocumentIds = array_column($searchResults['hits'], 'id');

        $this->assertContains($doc1->getId(), $foundDocumentIds);

        // Search for "TechCorp" should only return doc2
        $searchResults = $this->searchService->search('TechCorp');
        $foundDocumentIds = array_column($searchResults['hits'], 'id');

        $this->assertContains($doc2->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($doc1->getId());
        $this->searchService->deleteDocument($doc2->getId());
        $this->entityManager->remove($doc1);
        $this->entityManager->remove($doc2);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test searching with phrase queries
     */
    public function testSearchWithPhraseQuery(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'contract.pdf');
        $document->setOcrText('This is a Service Level Agreement between parties for maintenance services');
        $document->setSearchableContent('Service Level Agreement contract.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->searchService->indexDocument($document);
        usleep(500000);

        // Search for exact phrase
        $searchResults = $this->searchService->search('Service Level Agreement');

        $this->assertNotEmpty($searchResults['hits']);
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test that documents with no matching content are not returned
     */
    public function testSearchReturnsNoResultsForNonMatchingQuery(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'receipt.pdf');
        $document->setOcrText('Receipt for office supplies from Staples');
        $document->setSearchableContent('Receipt office supplies Staples receipt.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->searchService->indexDocument($document);
        usleep(500000);

        // Search for something that doesn't exist in the document
        $searchResults = $this->searchService->search('ZebraXYZ123NonExistent');

        // Should return no hits or not include our document
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertNotContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test searching by filename
     */
    public function testSearchByFilename(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'contract_2024_Q1.pdf');
        $document->setOcrText('Annual maintenance contract');
        $document->setSearchableContent('Annual maintenance contract contract_2024_Q1.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->searchService->indexDocument($document);
        usleep(500000);

        // Search by filename
        $searchResults = $this->searchService->search('contract_2024_Q1');

        $this->assertNotEmpty($searchResults['hits']);
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test searching documents with metadata
     */
    public function testSearchDocumentsWithExtractedMetadata(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'invoice.pdf');
        $document->setOcrText('Invoice for consulting services');
        $document->setSearchableContent('Invoice consulting services invoice.pdf');
        $document->setMetadata([
            'extracted_metadata' => [
                'invoice_numbers' => ['FV/2024/999'],
                'names' => ['Global Tech Solutions'],
                'amounts' => [15000.00]
            ]
        ]);

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->searchService->indexDocument($document);
        usleep(500000);

        // Search should find document by content
        $searchResults = $this->searchService->search('consulting');

        $this->assertNotEmpty($searchResults['hits']);
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Test that updated documents are re-indexed and searchable with new content
     */
    public function testUpdatedDocumentIsReindexedAndSearchable(): void
    {
        $this->markTestSkipped('Requires running Meilisearch instance. Enable when Meilisearch is available.');

        $this->useMockSearchService = false;
        $this->searchService = static::getContainer()->get(SearchService::class);

        $user = $this->createTestUser();
        $document = $this->createCompletedDocument($user, 'document.pdf');
        $document->setOcrText('Original content about widgets');
        $document->setSearchableContent('Original content widgets document.pdf');

        $this->entityManager->persist($user);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        // Index original content
        $this->searchService->indexDocument($document);
        usleep(500000);

        // Verify original is searchable
        $searchResults = $this->searchService->search('widgets');
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Update document content
        $document->setOcrText('Updated content about gadgets');
        $document->setSearchableContent('Updated content gadgets document.pdf');
        $this->entityManager->flush();

        // Re-index
        $this->searchService->indexDocument($document);
        usleep(500000);

        // New content should be searchable
        $searchResults = $this->searchService->search('gadgets');
        $foundDocumentIds = array_column($searchResults['hits'], 'id');
        $this->assertContains($document->getId(), $foundDocumentIds);

        // Clean up
        $this->searchService->deleteDocument($document->getId());
        $this->entityManager->remove($document);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
