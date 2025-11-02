<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\UpdateProcessingStatusMessage;
use App\Service\DocumentProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateProcessingStatusMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentProcessingService $processingService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdateProcessingStatusMessage $message): void
    {
        $documentId = $message->getDocumentId();

        try {
            // Find document
            $document = $this->entityManager
                ->getRepository(Document::class)
                ->find($documentId);

            if (!$document) {
                $this->logger->error('Document not found for status update', [
                    'document_id' => $documentId
                ]);
                return;
            }

            $this->logger->info('Updating document processing status from queue', [
                'document_id' => $documentId,
                'current_status' => $document->getProcessingStatus()
            ]);

            // Update processing status
            $this->processingService->updateProcessingStatus($document);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update document processing status', [
                'document_id' => $documentId,
                'error' => $e->getMessage()
            ]);

            // Don't rethrow - let Messenger handle retry logic
        }
    }
}
