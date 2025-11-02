<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Search API Controller
 *
 * Provides search functionality for documents with advanced filtering
 *
 * Features:
 * - Full-text search across document content and metadata
 * - Filter by category, tags, and date range
 * - Pagination support
 * - Sorting options
 */
#[Route('/api')]
#[IsGranted('ROLE_USER')]
class SearchController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Search documents
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        // Get query parameter
        $query = $request->query->get('q');

        if (!$query) {
            return new JsonResponse([
                'error' => 'Query parameter "q" is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get pagination parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        // Get filter parameters
        $categoryId = $request->query->get('category');
        $tags = $request->query->all('tags') ?? [];
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        // Build query
        $qb = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d');

        // Search in filename, original name, and OCR text
        $qb->where(
            $qb->expr()->orX(
                $qb->expr()->like('d.filename', ':query'),
                $qb->expr()->like('d.originalName', ':query'),
                $qb->expr()->like('d.ocrText', ':query'),
                $qb->expr()->like('d.searchableContent', ':query')
            )
        )
        ->setParameter('query', '%' . $query . '%');

        // Apply category filter
        if ($categoryId) {
            $qb->andWhere('d.category = :category')
                ->setParameter('category', $categoryId);
        }

        // Apply tag filter
        if (!empty($tags)) {
            $qb->join('d.tags', 't')
                ->andWhere('t.name IN (:tags)')
                ->setParameter('tags', $tags);
        }

        // Apply date range filter
        if ($dateFrom) {
            try {
                $fromDate = new \DateTimeImmutable($dateFrom);
                $qb->andWhere('d.createdAt >= :dateFrom')
                    ->setParameter('dateFrom', $fromDate);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }

        if ($dateTo) {
            try {
                $toDate = new \DateTimeImmutable($dateTo . ' 23:59:59');
                $qb->andWhere('d.createdAt <= :dateTo')
                    ->setParameter('dateTo', $toDate);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }

        // Get total count
        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Apply pagination
        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->orderBy('d.createdAt', 'DESC');

        // Execute query
        $documents = $qb->getQuery()->getResult();

        // Format results
        $results = array_map(function (Document $document) {
            $metadata = $document->getMetadata() ?? [];

            return [
                'id' => $document->getId(),
                'filename' => $document->getOriginalName() ?? $document->getFilename(),
                'mimeType' => $document->getMimeType(),
                'fileSize' => $document->getFileSize(),
                'title' => $metadata['title'] ?? null,
                'description' => $metadata['description'] ?? null,
                'processingStatus' => $document->getProcessingStatus(),
                'thumbnailPath' => $document->getThumbnailPath(),
                'createdAt' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt' => $document->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $documents);

        return new JsonResponse([
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'query' => $query,
        ]);
    }
}
