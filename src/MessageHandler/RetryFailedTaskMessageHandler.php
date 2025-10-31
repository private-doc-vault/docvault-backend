<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\RetryFailedTaskMessage;
use App\Service\DocumentProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for manual retry of failed document processing tasks
 */
#[AsMessageHandler]
final class RetryFailedTaskMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentProcessingService $processingService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(RetryFailedTaskMessage $message): void
    {
        $documentId = $message->getDocumentId();
        $reason = $message->getReason();

        try {
            // Find document
            $document = $this->entityManager
                ->getRepository(Document::class)
                ->find($documentId);

            if (!$document) {
                $this->logger->error('Document not found for manual retry', [
                    'document_id' => $documentId,
                    'reason' => $reason
                ]);
                return;
            }

            // Check if document is in failed state
            if ($document->getProcessingStatus() !== Document::STATUS_FAILED) {
                $this->logger->warning('Cannot retry document that is not in failed state', [
                    'document_id' => $documentId,
                    'current_status' => $document->getProcessingStatus(),
                    'reason' => $reason
                ]);
                return;
            }

            $this->logger->info('Manually retrying failed document processing', [
                'document_id' => $documentId,
                'reason' => $reason,
                'previous_error' => $document->getProcessingError()
            ]);

            // Retry processing
            $this->processingService->retryProcessing($document);

            $this->logger->info('Manual retry initiated successfully', [
                'document_id' => $documentId
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to manually retry document processing', [
                'document_id' => $documentId,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Rethrow to let Messenger handle retry
        }
    }
}
