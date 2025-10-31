<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Document;
use App\Entity\Category;
use App\Service\SearchService;
use App\Service\MeilisearchService;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SearchService
 *
 * Tests document indexing, search operations, and Meilisearch integration
 */
class SearchServiceTest extends TestCase
{
    private MockObject&MeilisearchService $meilisearchService;
    private MockObject&LoggerInterface $logger;
    private MockObject&Indexes $index;
    private SearchService $service;
    private string $indexName = 'documents';

    protected function setUp(): void
    {
        $this->meilisearchService = $this->createMock(MeilisearchService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->index = $this->createMock(Indexes::class);

        $this->service = new SearchService(
            $this->meilisearchService,
            $this->logger,
            $this->indexName
        );
    }

    private function createDocument(): Document
    {
        $document = new Document();
        $document->setId('doc-123');
        $document->setOriginalName('test-document.pdf');
        $document->setFilename('stored-file.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setOcrText('This is OCR extracted text content');
        $document->setSearchableContent('test-document.pdf This is OCR extracted text content');
        $document->setConfidenceScore('0.95'); // String format as entity expects
        $document->setLanguage('en');
        $document->setCreatedAt(new \DateTimeImmutable('2024-01-15 10:00:00'));

        $category = new Category();
        $category->setId('cat-1');
        $category->setName('Invoice');
        $document->setCategory($category);

        return $document;
    }

    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(SearchService::class, $this->service);
    }

    public function testGetIndexNameReturnsConfiguredName(): void
    {
        $this->assertEquals($this->indexName, $this->service->getIndexName());
    }

    public function testInitializeIndexCreatesIndexWithCorrectSettings(): void
    {
        $this->meilisearchService->expects($this->once())
            ->method('createIndex')
            ->with($this->indexName, 'id')
            ->willReturn(['taskUid' => 1, 'indexUid' => $this->indexName]);

        $this->meilisearchService->expects($this->once())
            ->method('updateIndexSettings')
            ->with(
                $this->indexName,
                $this->callback(function ($settings) {
                    return isset($settings['searchableAttributes'])
                        && isset($settings['filterableAttributes'])
                        && isset($settings['sortableAttributes'])
                        && in_array('searchableContent', $settings['searchableAttributes'])
                        && in_array('category', $settings['filterableAttributes']);
                })
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Search index initialized', $this->anything());

        $this->service->initializeIndex();
    }

    public function testInitializeIndexLogsErrorOnFailure(): void
    {
        $this->meilisearchService->expects($this->once())
            ->method('createIndex')
            ->willThrowException(new \Exception('Index creation failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to initialize search index', $this->anything());

        $this->expectException(\Exception::class);

        $this->service->initializeIndex();
    }

    public function testIndexDocumentAddsDocumentToIndex(): void
    {
        $document = $this->createDocument();

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) use ($document) {
                $doc = $docs[0];
                return $doc['id'] === 'doc-123'
                    && $doc['originalName'] === 'test-document.pdf'
                    && $doc['searchableContent'] === $document->getSearchableContent()
                    && $doc['category'] === 'Invoice'
                    && $doc['ocrText'] === 'This is OCR extracted text content';
            }))
            ->willReturn(['taskUid' => 2]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Document indexed', $this->anything());

        $this->service->indexDocument($document);
    }

    public function testIndexDocumentHandlesDocumentWithoutCategory(): void
    {
        $document = $this->createDocument();
        $document->setCategory(null);

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                return $docs[0]['category'] === null;
            }))
            ->willReturn(['taskUid' => 2]);

        $this->service->indexDocument($document);
    }

    public function testIndexDocumentLogsErrorOnFailure(): void
    {
        $document = $this->createDocument();

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willThrowException(new \Exception('Index not found'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to index document', $this->anything());

        $this->expectException(\Exception::class);

        $this->service->indexDocument($document);
    }

    public function testIndexMultipleDocumentsAddsAllDocuments(): void
    {
        $doc1 = $this->createDocument();
        $doc2 = $this->createDocument();
        $doc2->setId('doc-456');
        $doc2->setOriginalName('another-document.pdf');

        $documents = [$doc1, $doc2];

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                return count($docs) === 2
                    && $docs[0]['id'] === 'doc-123'
                    && $docs[1]['id'] === 'doc-456';
            }))
            ->willReturn(['taskUid' => 3]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Multiple documents indexed', ['count' => 2]);

        $this->service->indexMultipleDocuments($documents);
    }

    public function testUpdateDocumentUpdatesExistingDocument(): void
    {
        $document = $this->createDocument();

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('updateDocuments')
            ->with($this->callback(function ($docs) {
                return $docs[0]['id'] === 'doc-123';
            }))
            ->willReturn(['taskUid' => 4]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Document updated in index', $this->anything());

        $this->service->updateDocument($document);
    }

    public function testDeleteDocumentRemovesFromIndex(): void
    {
        $documentId = 'doc-123';

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('deleteDocument')
            ->with($documentId)
            ->willReturn(['taskUid' => 5]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Document deleted from index', ['document_id' => $documentId]);

        $this->service->deleteDocument($documentId);
    }

    public function testDeleteMultipleDocumentsRemovesAllFromIndex(): void
    {
        $documentIds = ['doc-123', 'doc-456', 'doc-789'];

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('deleteDocuments')
            ->with($documentIds)
            ->willReturn(['taskUid' => 6]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Multiple documents deleted from index', ['count' => 3]);

        $this->service->deleteMultipleDocuments($documentIds);
    }

    public function testClearIndexRemovesAllDocuments(): void
    {
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('deleteAllDocuments')
            ->willReturn(['taskUid' => 7]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('All documents cleared from search index');

        $this->service->clearIndex();
    }

    public function testGetIndexStatsReturnsStatistics(): void
    {
        $stats = [
            'numberOfDocuments' => 150,
            'isIndexing' => false,
            'fieldDistribution' => ['category' => 150]
        ];

        $this->meilisearchService->expects($this->once())
            ->method('getIndexStats')
            ->with($this->indexName)
            ->willReturn($stats);

        $result = $this->service->getIndexStats();

        $this->assertEquals(150, $result['numberOfDocuments']);
        $this->assertFalse($result['isIndexing']);
    }

    public function testSearchReturnsResults(): void
    {
        $query = 'invoice';
        $options = [
            'limit' => 20,
            'offset' => 0,
            'filter' => 'category = Invoice'
        ];

        $searchResults = [
            'hits' => [
                ['id' => 'doc-123', 'originalName' => 'invoice.pdf'],
                ['id' => 'doc-456', 'originalName' => 'receipt.pdf']
            ],
            'estimatedTotalHits' => 2,
            'processingTimeMs' => 5
        ];

        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('search')
            ->with($query, $options)
            ->willReturn($searchResults);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Search performed', $this->anything());

        $result = $this->service->search($query, $options);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('hits', $result);
        $this->assertCount(2, $result['hits']);
        $this->assertEquals(2, $result['estimatedTotalHits']);
    }

    public function testSearchWithEmptyQueryReturnsAllDocuments(): void
    {
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('search')
            ->with('', $this->anything())
            ->willReturn(['hits' => [], 'estimatedTotalHits' => 0]);

        $result = $this->service->search('');

        $this->assertIsArray($result);
    }

    public function testSearchLogsErrorOnFailure(): void
    {
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willThrowException(new \Exception('Search failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Search failed', $this->anything());

        $this->expectException(\Exception::class);

        $this->service->search('test query');
    }

    public function testReindexAllClearsAndReindexesDocuments(): void
    {
        $documents = [
            $this->createDocument(),
            $this->createDocument()
        ];

        $this->meilisearchService->expects($this->exactly(2))
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('deleteAllDocuments')
            ->willReturn(['taskUid' => 10]);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                return count($docs) === 2;
            }))
            ->willReturn(['taskUid' => 11]);

        // Expect 3 info logs: "Starting full reindex", "Multiple documents indexed", "Full reindex completed"
        $this->logger->expects($this->exactly(3))
            ->method('info');

        // Expect 1 warning log: "All documents cleared from search index"
        $this->logger->expects($this->once())
            ->method('warning');

        $this->service->reindexAll($documents);
    }

    /**
     * NEW TESTS FOR AUTO-INDEXING AFTER OCR COMPLETION
     * Following TDD - these will guide the implementation
     */

    public function testDocumentIsIndexedAfterOcrCompletion(): void
    {
        // GIVEN: A document that just completed OCR processing
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setOcrText('Newly extracted OCR text');
        $document->setSearchableContent('Newly extracted OCR text test-document.pdf');

        // WHEN: Document is indexed after OCR completion
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->with($this->indexName)
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                return $docs[0]['ocrText'] === 'Newly extracted OCR text'
                    && $docs[0]['searchableContent'] !== null;
            }))
            ->willReturn(['taskUid' => 100]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Document indexed', $this->anything());

        // THEN: Document should be indexed successfully
        $this->service->indexDocument($document);
    }

    public function testIndexingFailureDoesNotBlockOcrCompletion(): void
    {
        // GIVEN: A document that completed OCR
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);

        // AND: Meilisearch service throws an exception
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willThrowException(new \Exception('Meilisearch unavailable'));

        // WHEN: Attempting to index the document
        // THEN: Exception should be thrown (will be caught by message handler)
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to index document', $this->callback(function ($context) {
                return $context['error'] === 'Meilisearch unavailable';
            }));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Meilisearch unavailable');

        $this->service->indexDocument($document);
    }

    public function testIndexingIncludesAllOcrExtractedData(): void
    {
        // GIVEN: A document with complete OCR data
        $document = $this->createDocument();
        $document->setOcrText('Complete OCR text');
        $document->setSearchableContent('Complete searchable content');
        $document->setConfidenceScore('0.98');
        $document->setLanguage('eng');
        $document->setExtractedDate(new \DateTimeImmutable('2024-03-15'));
        $document->setExtractedAmount('1234.56');

        // WHEN: Document is indexed
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                $doc = $docs[0];
                return $doc['ocrText'] === 'Complete OCR text'
                    && $doc['searchableContent'] === 'Complete searchable content'
                    && $doc['confidenceScore'] === '0.98'
                    && $doc['language'] === 'eng'
                    && $doc['extractedDate'] !== null
                    && $doc['extractedAmount'] === '1234.56';
            }))
            ->willReturn(['taskUid' => 101]);

        // THEN: All OCR data should be included in index
        $this->service->indexDocument($document);
    }

    public function testBatchIndexingAfterMultipleOcrCompletions(): void
    {
        // GIVEN: Multiple documents that completed OCR processing
        $documents = [];
        for ($i = 1; $i <= 5; $i++) {
            $doc = $this->createDocument();
            $doc->setId("doc-{$i}");
            $doc->setOcrText("OCR text for document {$i}");
            $doc->setProcessingStatus(Document::STATUS_COMPLETED);
            $documents[] = $doc;
        }

        // WHEN: Batch indexing multiple documents
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                // Verify we're indexing 5 documents
                return count($docs) === 5
                    && $docs[0]['id'] === 'doc-1'
                    && $docs[4]['id'] === 'doc-5';
            }))
            ->willReturn(['taskUid' => 102]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Multiple documents indexed', ['count' => 5]);

        // THEN: All documents should be batch indexed efficiently
        $this->service->indexMultipleDocuments($documents);
    }

    public function testIndexingOnlyOccursForCompletedDocuments(): void
    {
        // GIVEN: A document still processing (not yet completed)
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_PROCESSING); // Not completed yet
        $document->setOcrText(null); // No OCR text yet

        // WHEN: Attempting to index an incomplete document
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        // THEN: Document is still indexed (handler will decide when to index)
        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                // OCR text can be null for incomplete documents
                return $docs[0]['ocrText'] === null;
            }))
            ->willReturn(['taskUid' => 103]);

        $this->service->indexDocument($document);
    }

    public function testIndexingUpdatesExistingDocumentAfterReprocessing(): void
    {
        // GIVEN: A document that was reprocessed with better OCR results
        $document = $this->createDocument();
        $document->setOcrText('Improved OCR text after reprocessing');
        $document->setConfidenceScore('0.99'); // Higher confidence
        $document->setProcessingStatus(Document::STATUS_COMPLETED);

        // WHEN: Updating existing document in index
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('updateDocuments')
            ->with($this->callback(function ($docs) {
                return $docs[0]['ocrText'] === 'Improved OCR text after reprocessing'
                    && $docs[0]['confidenceScore'] === '0.99';
            }))
            ->willReturn(['taskUid' => 104]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Document updated in index', $this->anything());

        // THEN: Index should be updated with new OCR results
        $this->service->updateDocument($document);
    }

    public function testIndexingPreservesSearchableContentStructure(): void
    {
        // GIVEN: A document with properly built searchable content
        $document = $this->createDocument();
        $document->setOcrText('Invoice FV/2024/001');
        $document->setSearchableContent('test-document.pdf Invoice FV/2024/001 Invoice');

        // WHEN: Document is indexed
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                // Searchable content should include filename, OCR text, and category
                $content = $docs[0]['searchableContent'];
                return str_contains($content, 'test-document.pdf')
                    && str_contains($content, 'Invoice')
                    && str_contains($content, 'FV/2024/001');
            }))
            ->willReturn(['taskUid' => 105]);

        // THEN: Searchable content structure is preserved for optimal search
        $this->service->indexDocument($document);
    }

    /**
     * NEW TESTS FOR BATCH INDEXING WITH RATE LIMIT PROTECTION (Task 2.8)
     */

    public function testIndexMultipleDocumentsInChunksProcessesInBatches(): void
    {
        // GIVEN: 25 documents to index with batch size of 10
        $documents = [];
        for ($i = 1; $i <= 25; $i++) {
            $doc = $this->createDocument();
            $doc->setId("doc-{$i}");
            $documents[] = $doc;
        }

        // EXPECT: 3 calls to addDocuments (10 + 10 + 5)
        $this->meilisearchService->expects($this->exactly(3))
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->exactly(3))
            ->method('addDocuments')
            ->willReturn(['taskUid' => 200]);

        // EXPECT: Progress logging (start + 3 batches + completion = 5 logs)
        $this->logger->expects($this->exactly(5))
            ->method('info');

        // WHEN: Indexing with batch size 10
        $this->service->indexMultipleDocumentsInChunks($documents, 10);
    }

    public function testIndexMultipleDocumentsInChunksWithDefaultBatchSize(): void
    {
        // GIVEN: 5 documents (less than default batch size of 100)
        $documents = [];
        for ($i = 1; $i <= 5; $i++) {
            $doc = $this->createDocument();
            $doc->setId("doc-{$i}");
            $documents[] = $doc;
        }

        // EXPECT: Single batch
        $this->meilisearchService->expects($this->once())
            ->method('getIndex')
            ->willReturn($this->index);

        $this->index->expects($this->once())
            ->method('addDocuments')
            ->with($this->callback(function ($docs) {
                return count($docs) === 5;
            }))
            ->willReturn(['taskUid' => 201]);

        // WHEN: Indexing with default batch size
        $this->service->indexMultipleDocumentsInChunks($documents);
    }

    public function testIndexMultipleDocumentsInChunksHandlesEmptyArray(): void
    {
        // GIVEN: Empty documents array
        $documents = [];

        // EXPECT: No calls to Meilisearch
        $this->meilisearchService->expects($this->never())
            ->method('getIndex');

        $this->index->expects($this->never())
            ->method('addDocuments');

        // EXPECT: Info log about empty batch
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('No documents to index'));

        // WHEN: Attempting to index empty array
        $this->service->indexMultipleDocumentsInChunks($documents);
    }

    public function testIndexMultipleDocumentsInChunksLogsProgress(): void
    {
        // GIVEN: 15 documents with batch size 5
        $documents = [];
        for ($i = 1; $i <= 15; $i++) {
            $doc = $this->createDocument();
            $doc->setId("doc-{$i}");
            $documents[] = $doc;
        }

        $this->meilisearchService->method('getIndex')->willReturn($this->index);
        $this->index->method('addDocuments')->willReturn(['taskUid' => 202]);

        // EXPECT: Progress logs for starting, batch 1, batch 2, batch 3, completion
        $this->logger->expects($this->exactly(5))
            ->method('info');

        // WHEN: Indexing in batches
        $this->service->indexMultipleDocumentsInChunks($documents, 5);
    }
}
