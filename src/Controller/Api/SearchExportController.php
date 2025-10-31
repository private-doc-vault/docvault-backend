<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\SearchExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Search Export API Controller
 *
 * Handles exporting search results to various formats
 */
#[Route('/api/search/export')]
#[IsGranted('ROLE_USER')]
class SearchExportController extends AbstractController
{
    public function __construct(
        private readonly SearchExportService $exportService
    ) {
    }

    /**
     * Export search results
     *
     * @param Request $request
     * @return StreamedResponse|Response
     */
    #[Route('', name: 'api_search_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse|Response
    {
        $query = $request->query->get('q', '');
        $format = $request->query->get('format', 'csv');

        // Build filters from request
        $filters = $this->buildFilters($request);

        // Get current user
        $user = $this->getUser();

        try {
            return match ($format) {
                'csv' => $this->exportService->exportToCsv($query, $filters, $user),
                'excel', 'xlsx' => $this->exportService->exportToExcel($query, $filters, $user),
                'pdf' => $this->exportService->exportToPdf($query, $filters, $user),
                default => $this->json([
                    'error' => 'Unsupported format',
                    'supported' => array_keys($this->exportService->getSupportedFormats())
                ], Response::HTTP_BAD_REQUEST)
            };
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get supported export formats
     *
     * @return Response
     */
    #[Route('/formats', name: 'api_search_export_formats', methods: ['GET'])]
    public function formats(): Response
    {
        return $this->json([
            'formats' => $this->exportService->getSupportedFormats()
        ]);
    }

    /**
     * Build search filters from request parameters
     *
     * @param Request $request
     * @return array
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        // Category filter
        $category = $request->query->get('category');
        if ($category) {
            $filters['category'] = $category;
        }

        // Language filter
        $language = $request->query->get('language');
        if ($language) {
            $filters['language'] = $language;
        }

        // Date range filters
        $dateFrom = $request->query->get('dateFrom');
        if ($dateFrom) {
            $filters['dateFrom'] = $dateFrom;
        }

        $dateTo = $request->query->get('dateTo');
        if ($dateTo) {
            $filters['dateTo'] = $dateTo;
        }

        // Confidence filter
        $minConfidence = $request->query->get('minConfidence');
        if ($minConfidence !== null) {
            $filters['minConfidence'] = (float) $minConfidence;
        }

        // Tags
        $tags = $request->query->all('tags');
        if (!empty($tags)) {
            $filters['tags'] = $tags;
        }

        // File size filters
        $fileSizeMin = $request->query->get('fileSizeMin');
        if ($fileSizeMin !== null) {
            $filters['fileSizeMin'] = (int) $fileSizeMin;
        }

        $fileSizeMax = $request->query->get('fileSizeMax');
        if ($fileSizeMax !== null) {
            $filters['fileSizeMax'] = (int) $fileSizeMax;
        }

        return $filters;
    }
}
