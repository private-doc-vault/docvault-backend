<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use Psr\Log\LoggerInterface;

/**
 * Search Service
 *
 * Provides document indexing and search functionality using Meilisearch:
 * - Index management and initialization
 * - Document indexing (single and batch)
 * - Document updates and deletions
 * - Full-text search operations
 * - Index statistics and monitoring
 */
class SearchService
{
    /**
     * Searchable attributes for full-text search
     */
    private const SEARCHABLE_ATTRIBUTES = [
        'searchableContent',
        'originalName',
        'ocrText',
        'filename',
    ];

    /**
     * Filterable attributes for faceted search
     */
    private const FILTERABLE_ATTRIBUTES = [
        'category',
        'mimeType',
        'language',
        'createdAt',
        'confidenceScore',
        'fileSize',
    ];

    /**
     * Sortable attributes for result ordering
     */
    private const SORTABLE_ATTRIBUTES = [
        'createdAt',
        'originalName',
        'fileSize',
        'confidenceScore',
    ];

    public function __construct(
        private readonly MeilisearchService $meilisearchService,
        private readonly LoggerInterface $logger,
        private readonly string $indexName = 'documents'
    ) {
    }

    /**
     * Get the index name
     *
     * @return string
     */
    public function getIndexName(): string
    {
        return $this->indexName;
    }

    /**
     * Initialize the search index with proper settings
     *
     * @return void
     * @throws \Exception
     */
    public function initializeIndex(): void
    {
        try {
            // Create the index
            $this->meilisearchService->createIndex($this->indexName, 'id');

            // Configure index settings
            $settings = [
                'searchableAttributes' => self::SEARCHABLE_ATTRIBUTES,
                'filterableAttributes' => self::FILTERABLE_ATTRIBUTES,
                'sortableAttributes' => self::SORTABLE_ATTRIBUTES,
                'displayedAttributes' => ['*'],
                'rankingRules' => [
                    'words',
                    'typo',
                    'proximity',
                    'attribute',
                    'sort',
                    'exactness',
                    'confidenceScore:desc',
                ],
            ];

            $this->meilisearchService->updateIndexSettings($this->indexName, $settings);

            $this->logger->info('Search index initialized', [
                'index' => $this->indexName
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize search index', [
                'index' => $this->indexName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Index a single document
     *
     * @param Document $document
     * @return void
     * @throws \Exception
     */
    public function indexDocument(Document $document): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $documentData = $this->transformDocumentForIndex($document);

            $index->addDocuments([$documentData]);

            $this->logger->info('Document indexed', [
                'document_id' => $document->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to index document', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Index multiple documents in batch
     *
     * @param array<Document> $documents
     * @return void
     * @throws \Exception
     */
    public function indexMultipleDocuments(array $documents): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $documentsData = array_map(
                fn(Document $doc) => $this->transformDocumentForIndex($doc),
                $documents
            );

            $index->addDocuments($documentsData);

            $this->logger->info('Multiple documents indexed', [
                'count' => count($documents)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to index multiple documents', [
                'count' => count($documents),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Index multiple documents in chunks with rate limit protection
     *
     * Processes large batches of documents in smaller chunks to avoid
     * overwhelming Meilisearch and hitting rate limits.
     *
     * @param array<Document> $documents Documents to index
     * @param int $batchSize Number of documents per batch (default: 100)
     * @param int $delayMs Delay between batches in milliseconds (default: 0)
     * @return void
     * @throws \Exception
     */
    public function indexMultipleDocumentsInChunks(
        array $documents,
        int $batchSize = 100,
        int $delayMs = 0
    ): void {
        $totalCount = count($documents);

        if ($totalCount === 0) {
            $this->logger->info('No documents to index in batch');
            return;
        }

        $this->logger->info('Starting batch indexing', [
            'total_documents' => $totalCount,
            'batch_size' => $batchSize,
            'estimated_batches' => ceil($totalCount / $batchSize)
        ]);

        $chunks = array_chunk($documents, $batchSize);
        $processedCount = 0;

        foreach ($chunks as $batchIndex => $chunk) {
            try {
                $index = $this->meilisearchService->getIndex($this->indexName);

                $documentsData = array_map(
                    fn(Document $doc) => $this->transformDocumentForIndex($doc),
                    $chunk
                );

                $index->addDocuments($documentsData);

                $processedCount += count($chunk);

                $this->logger->info('Batch indexed successfully', [
                    'batch' => $batchIndex + 1,
                    'batch_size' => count($chunk),
                    'processed' => $processedCount,
                    'total' => $totalCount,
                    'progress_percent' => round(($processedCount / $totalCount) * 100, 2)
                ]);

                // Add delay between batches if specified (rate limiting)
                if ($delayMs > 0 && $batchIndex < count($chunks) - 1) {
                    usleep($delayMs * 1000);
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to index batch', [
                    'batch' => $batchIndex + 1,
                    'batch_size' => count($chunk),
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        $this->logger->info('Batch indexing completed', [
            'total_indexed' => $processedCount,
            'batches_processed' => count($chunks)
        ]);
    }

    /**
     * Update a document in the index
     *
     * @param Document $document
     * @return void
     * @throws \Exception
     */
    public function updateDocument(Document $document): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $documentData = $this->transformDocumentForIndex($document);

            $index->updateDocuments([$documentData]);

            $this->logger->info('Document updated in index', [
                'document_id' => $document->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update document in index', [
                'document_id' => $document->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a document from the index
     *
     * @param string $documentId
     * @return void
     * @throws \Exception
     */
    public function deleteDocument(string $documentId): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $index->deleteDocument($documentId);

            $this->logger->info('Document deleted from index', [
                'document_id' => $documentId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete document from index', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete multiple documents from the index
     *
     * @param array<string> $documentIds
     * @return void
     * @throws \Exception
     */
    public function deleteMultipleDocuments(array $documentIds): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $index->deleteDocuments($documentIds);

            $this->logger->info('Multiple documents deleted from index', [
                'count' => count($documentIds)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete multiple documents from index', [
                'count' => count($documentIds),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Clear all documents from the index
     *
     * @return void
     * @throws \Exception
     */
    public function clearIndex(): void
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $index->deleteAllDocuments();

            $this->logger->warning('All documents cleared from search index');
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear search index', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get index statistics
     *
     * @return array
     */
    public function getIndexStats(): array
    {
        return $this->meilisearchService->getIndexStats($this->indexName);
    }

    /**
     * Perform a search
     *
     * @param string $query
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function search(string $query, array $options = []): array
    {
        try {
            $index = $this->meilisearchService->getIndex($this->indexName);

            $result = $index->search($query, $options);

            $this->logger->info('Search performed', [
                'query' => $query,
                'hits' => $result['estimatedTotalHits'] ?? 0,
                'processing_time_ms' => $result['processingTimeMs'] ?? 0
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Search failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reindex all documents (clear and rebuild index)
     *
     * @param array<Document> $documents
     * @return void
     * @throws \Exception
     */
    public function reindexAll(array $documents): void
    {
        try {
            // Clear existing index
            $this->clearIndex();

            $this->logger->info('Starting full reindex', [
                'document_count' => count($documents)
            ]);

            // Index all documents
            $this->indexMultipleDocuments($documents);

            $this->logger->info('Full reindex completed', [
                'document_count' => count($documents)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Full reindex failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Transform a Document entity to index format
     *
     * @param Document $document
     * @return array
     */
    private function transformDocumentForIndex(Document $document): array
    {
        return [
            'id' => $document->getId(),
            'originalName' => $document->getOriginalName(),
            'filename' => $document->getFilename(),
            'mimeType' => $document->getMimeType(),
            'fileSize' => $document->getFileSize(),
            'ocrText' => $document->getOcrText(),
            'searchableContent' => $document->getSearchableContent(),
            'confidenceScore' => $document->getConfidenceScore(),
            'language' => $document->getLanguage(),
            'category' => $document->getCategory()?->getName(),
            'createdAt' => $document->getCreatedAt()?->getTimestamp(),
            'extractedDate' => $document->getExtractedDate()?->getTimestamp(),
            'extractedAmount' => $document->getExtractedAmount(),
        ];
    }
}
