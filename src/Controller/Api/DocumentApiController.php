<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API endpoints for document management
 */
#[Route('/api/documents', name: 'api_documents_')]
#[IsGranted('ROLE_USER')]
class DocumentApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all documents
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();

        // Get pagination parameters
        $limit = (int) ($request->query->get('limit', 20));
        $offset = (int) ($request->query->get('offset', 0));
        $sort = $request->query->get('sort', 'createdAt');
        $order = $request->query->get('order', 'DESC');

        // Build query
        $qb = $this->entityManager->getRepository(Document::class)->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->setParameter('user', $user)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        // Apply sorting
        $qb->orderBy('d.' . $sort, strtoupper($order));

        $documents = $qb->getQuery()->getResult();

        // Get total count
        $totalCount = $this->entityManager->getRepository(Document::class)->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.uploadedBy = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        // Serialize documents
        $documentData = array_map(function (Document $doc) {
            return [
                'id' => $doc->getId(),
                'filename' => $doc->getFilename(),
                'originalName' => $doc->getOriginalName(),
                'mimeType' => $doc->getMimeType(),
                'fileSize' => $doc->getFileSize(),
                'processingStatus' => $doc->getProcessingStatus(),
                'createdAt' => $doc->getCreatedAt()?->format('c'),
                'updatedAt' => $doc->getUpdatedAt()?->format('c'),
                'category' => $doc->getCategory() ? [
                    'id' => $doc->getCategory()->getId(),
                    'name' => $doc->getCategory()->getName(),
                ] : null,
                'tags' => array_map(fn($tag) => [
                    'id' => $tag->getId(),
                    'name' => $tag->getName(),
                ], $doc->getTags()->toArray()),
            ];
        }, $documents);

        return new JsonResponse([
            'documents' => $documentData,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Get specific document details
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->getUser();

        $document = $this->entityManager->getRepository(Document::class)->findOneBy([
            'id' => $id,
            'uploadedBy' => $user
        ]);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $documentData = [
            'id' => $document->getId(),
            'filename' => $document->getFilename(),
            'originalName' => $document->getOriginalName(),
            'mimeType' => $document->getMimeType(),
            'fileSize' => $document->getFileSize(),
            'filePath' => $document->getFilePath(),
            'ocrText' => $document->getOcrText(),
            'metadata' => $document->getMetadata(),
            'processingStatus' => $document->getProcessingStatus(),
            'processingError' => $document->getProcessingError(),
            'thumbnailPath' => $document->getThumbnailPath(),
            'createdAt' => $document->getCreatedAt()?->format('c'),
            'updatedAt' => $document->getUpdatedAt()?->format('c'),
            'extractedDate' => $document->getExtractedDate()?->format('Y-m-d'),
            'extractedAmount' => $document->getExtractedAmount(),
            'language' => $document->getLanguage(),
            'confidenceScore' => $document->getConfidenceScore(),
            'category' => $document->getCategory() ? [
                'id' => $document->getCategory()->getId(),
                'name' => $document->getCategory()->getName(),
            ] : null,
            'tags' => array_map(fn($tag) => [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'color' => $tag->getColor(),
            ], $document->getTags()->toArray()),
        ];

        return new JsonResponse(['document' => $documentData]);
    }

    /**
     * Update document metadata
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();

        $document = $this->entityManager->getRepository(Document::class)->findOneBy([
            'id' => $id,
            'uploadedBy' => $user
        ]);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        // Update allowed fields
        if (isset($data['metadata'])) {
            $document->setMetadata($data['metadata']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Document updated successfully',
            'document' => [
                'id' => $document->getId(),
                'filename' => $document->getFilename(),
            ]
        ]);
    }

    /**
     * Delete document
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();

        $document = $this->entityManager->getRepository(Document::class)->findOneBy([
            'id' => $id,
            'uploadedBy' => $user
        ]);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($document);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Document deleted successfully',
            'documentId' => $id
        ]);
    }
}
