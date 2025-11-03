<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Service\DocumentProcessingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/documents', name: 'api_document_processing_')]
class DocumentProcessingStatusController extends AbstractController
{
    public function __construct(
        private readonly DocumentProcessingService $processingService
    ) {
    }

    #[Route('/{id}/processing-status', name: 'status', methods: ['GET'])]
    public function getProcessingStatus(Document $document): JsonResponse
    {
        $status = $this->processingService->getProcessingStatus($document);

        return $this->json([
            'document_id' => $document->getId(),
            'status' => $status['status'],
            'progress' => $status['progress'],
            'current_operation' => $document->getCurrentOperation(),
            'error' => $status['error'],
            'task_id' => $status['task_id'],
            'ocrText' => $document->getOcrText(),
            'confidence_score' => $document->getConfidenceScore() ? (float) $document->getConfidenceScore() : null,
            'category' => $document->getCategory()?->getName(),
            'extracted_date' => $document->getExtractedDate()?->format('Y-m-d'),
            'extracted_amount' => $document->getExtractedAmount(),
        ]);
    }

    #[Route('/{id}/retry-processing', name: 'retry', methods: ['POST'])]
    public function retryProcessing(Document $document): JsonResponse
    {
        if ($document->getProcessingStatus() !== 'failed') {
            return $this->json([
                'error' => 'Document processing has not failed',
                'current_status' => $document->getProcessingStatus()
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->processingService->retryProcessing($document);

        return $this->json([
            'message' => 'Document processing retry initiated',
            'document_id' => $document->getId(),
            'status' => $document->getProcessingStatus()
        ]);
    }
}
