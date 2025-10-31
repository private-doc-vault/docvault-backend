<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\IndexDocumentMessage;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndexDocumentMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchService $searchService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(IndexDocumentMessage $message): void
    {
        $documentId = $message->getDocumentId();

        try {
            // Find document
            $document = $this->entityManager
                ->getRepository(Document::class)
                ->find($documentId);

            if (!$document) {
                $this->logger->error('Document not found for indexing', [
                    'document_id' => $documentId
                ]);
                return;
            }

            // Only index completed documents
            if ($document->getProcessingStatus() !== 'completed') {
                $this->logger->warning('Skipping indexing for non-completed document', [
                    'document_id' => $documentId,
                    'status' => $document->getProcessingStatus()
                ]);
                return;
            }

            // Skip documents without OCR text (null check)
            if ($document->getOcrText() === null) {
                $this->logger->warning('Skipping indexing for document without OCR text', [
                    'document_id' => $documentId
                ]);
                return;
            }

            // Index document in Meilisearch
            $this->searchService->indexDocument($document);

            $this->logger->info('Document indexed successfully', [
                'document_id' => $documentId,
                'filename' => $document->getFilename()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to index document', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Rethrow to trigger Messenger retry logic
            throw $e;
        }
    }
}
