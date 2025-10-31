<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\ProcessDocumentMessage;
use App\Service\DocumentProcessingService;
use App\Service\ErrorCategorization\ErrorCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessDocumentMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentProcessingService $processingService,
        private readonly LoggerInterface $logger,
        private readonly ErrorCategorizer $errorCategorizer
    ) {
    }

    public function __invoke(ProcessDocumentMessage $message): void
    {
        $documentId = $message->getDocumentId();

        try {
            // Find document
            $document = $this->entityManager
                ->getRepository(Document::class)
                ->find($documentId);

            if (!$document) {
                $this->logger->error('Document not found for processing', [
                    'document_id' => $documentId
                ]);
                return;
            }

            $this->logger->info('Processing document from queue', [
                'document_id' => $documentId,
                'filename' => $document->getFilename()
            ]);

            // Process document
            $this->processingService->processDocument($document);

        } catch (\Exception $e) {
            // Categorize the error
            $category = $this->errorCategorizer->categorize($e);

            $this->logger->error('Failed to process document from queue', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
                'error_category' => $category->value,
                'should_retry' => $category->isTransient(),
                'trace' => $e->getTraceAsString()
            ]);

            // Rethrow transient errors so Messenger can retry
            // Permanent errors are logged but not retried
            if ($category->isTransient()) {
                $this->logger->info('Retrying transient error', [
                    'document_id' => $documentId,
                    'error_type' => get_class($e)
                ]);
                throw $e;
            }

            $this->logger->warning('Permanent error - will not retry', [
                'document_id' => $documentId,
                'error_type' => get_class($e)
            ]);
        }
    }
}
