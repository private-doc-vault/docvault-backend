<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\SearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Meilisearch Search API Controller
 *
 * Provides high-performance full-text search using Meilisearch
 *
 * Features:
 * - Ultra-fast full-text search across all document content
 * - Faceted filtering by category, language, file type
 * - Advanced sorting options
 * - Pagination support
 * - Typo tolerance and relevance ranking
 */
#[Route('/api/search')]
#[IsGranted('ROLE_USER')]
class MeilisearchSearchController extends AbstractController
{
    public function __construct(
        private readonly SearchService $searchService
    ) {
    }

    /**
     * Search documents using Meilisearch
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/meilisearch', name: 'api_search_meilisearch', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        // Get query parameter
        $query = $request->query->get('q');

        if ($query === null) {
            return new JsonResponse([
                'error' => 'Query parameter "q" is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Build search options
        $options = $this->buildSearchOptions($request);

        try {
            // Perform search
            $results = $this->searchService->search($query, $options);

            // Return formatted response
            return new JsonResponse([
                'hits' => $results['hits'] ?? [],
                'query' => $query,
                'estimatedTotalHits' => $results['estimatedTotalHits'] ?? 0,
                'processingTimeMs' => $results['processingTimeMs'] ?? 0,
                'limit' => $results['limit'] ?? 20,
                'offset' => $results['offset'] ?? 0,
                'facetDistribution' => $results['facetDistribution'] ?? null,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Search failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get search suggestions/autocomplete
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route('/suggest', name: 'api_search_suggest', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        $query = $request->query->get('q');

        if (!$query || strlen($query) < 2) {
            return new JsonResponse([
                'suggestions' => []
            ]);
        }

        try {
            // Search with limited results for suggestions
            $results = $this->searchService->search($query, [
                'limit' => 5,
                'attributesToRetrieve' => ['id', 'originalName', 'category'],
            ]);

            // Extract suggestions
            $suggestions = array_map(function ($hit) {
                return [
                    'id' => $hit['id'] ?? null,
                    'text' => $hit['originalName'] ?? '',
                    'category' => $hit['category'] ?? null,
                ];
            }, $results['hits'] ?? []);

            return new JsonResponse([
                'query' => $query,
                'suggestions' => $suggestions
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Suggestion search failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get index statistics
     *
     * @return JsonResponse
     */
    #[Route('/stats', name: 'api_search_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->searchService->getIndexStats();

            return new JsonResponse([
                'numberOfDocuments' => $stats['numberOfDocuments'] ?? 0,
                'isIndexing' => $stats['isIndexing'] ?? false,
                'fieldDistribution' => $stats['fieldDistribution'] ?? [],
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to retrieve stats',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Build search options from request parameters
     *
     * @param Request $request
     * @return array
     */
    private function buildSearchOptions(Request $request): array
    {
        $options = [];

        // Pagination
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $options['limit'] = $limit;
        $options['offset'] = $offset;

        // Filters
        $filters = [];

        $category = $request->query->get('category');
        if ($category) {
            $filters[] = "category = \"$category\"";
        }

        $mimeType = $request->query->get('mimeType');
        if ($mimeType) {
            $filters[] = "mimeType = \"$mimeType\"";
        }

        $language = $request->query->get('language');
        if ($language) {
            $filters[] = "language = \"$language\"";
        }

        // Date range filter
        $dateFrom = $request->query->get('dateFrom');
        if ($dateFrom) {
            try {
                $timestamp = (new \DateTimeImmutable($dateFrom))->getTimestamp();
                $filters[] = "createdAt >= $timestamp";
            } catch (\Exception $e) {
                // Invalid date format, skip
            }
        }

        $dateTo = $request->query->get('dateTo');
        if ($dateTo) {
            try {
                $timestamp = (new \DateTimeImmutable($dateTo . ' 23:59:59'))->getTimestamp();
                $filters[] = "createdAt <= $timestamp";
            } catch (\Exception $e) {
                // Invalid date format, skip
            }
        }

        // Confidence score filter
        $minConfidence = $request->query->get('minConfidence');
        if ($minConfidence !== null) {
            $confidence = (float) $minConfidence;
            if ($confidence >= 0 && $confidence <= 1) {
                $filters[] = "confidenceScore >= $confidence";
            }
        }

        // Combine filters with AND
        if (!empty($filters)) {
            $options['filter'] = implode(' AND ', $filters);
        }

        // Sorting
        $sort = $request->query->get('sort');
        if ($sort) {
            $options['sort'] = [$sort];
        }

        // Attributes to retrieve
        $attributesToRetrieve = $request->query->all('attributes') ?? [];
        if (!empty($attributesToRetrieve)) {
            $options['attributesToRetrieve'] = $attributesToRetrieve;
        }

        // Highlighting
        $highlight = $request->query->get('highlight');
        if ($highlight) {
            $options['attributesToHighlight'] = ['*'];
        }

        // Facets
        $facets = $request->query->all('facets') ?? [];
        if (!empty($facets)) {
            $options['facets'] = $facets;
        }

        // Matching strategy
        $matchingStrategy = $request->query->get('matchingStrategy');
        if (in_array($matchingStrategy, ['all', 'last'])) {
            $options['matchingStrategy'] = $matchingStrategy;
        }

        return $options;
    }
}
