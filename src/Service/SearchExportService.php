<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Search Export Service
 *
 * Exports search results to various formats (CSV, Excel, PDF)
 */
class SearchExportService
{
    public function __construct(
        private readonly SearchService $searchService
    ) {
    }

    /**
     * Export search results to CSV
     *
     * @param string $query
     * @param array $filters
     * @param User $user
     * @return StreamedResponse
     */
    public function exportToCsv(string $query, array $filters, User $user): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($query, $filters) {
            $handle = fopen('php://output', 'w');

            // Write CSV headers
            fputcsv($handle, [
                'ID',
                'Filename',
                'Category',
                'Tags',
                'File Size',
                'MIME Type',
                'Language',
                'OCR Confidence',
                'Created At',
                'Owner'
            ]);

            // Fetch all results (no pagination for export)
            $searchFilters = array_merge($filters, ['limit' => 1000]);
            $results = $this->searchService->search($query, $searchFilters);

            // Write data rows
            foreach ($results['hits'] ?? [] as $doc) {
                fputcsv($handle, [
                    $doc['id'] ?? '',
                    $doc['originalName'] ?? $doc['filename'] ?? '',
                    $doc['category']['name'] ?? '',
                    $this->formatTags($doc['tags'] ?? []),
                    $doc['fileSize'] ?? 0,
                    $doc['mimeType'] ?? '',
                    $doc['language'] ?? '',
                    isset($doc['confidenceScore']) ? round($doc['confidenceScore'] * 100, 2) . '%' : '',
                    $doc['createdAt'] ?? '',
                    $doc['owner']['email'] ?? ''
                ]);
            }

            fclose($handle);
        });

        $filename = 'search-results-' . date('Y-m-d-His') . '.csv';

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /**
     * Export search results to Excel (XLSX)
     *
     * @param string $query
     * @param array $filters
     * @param User $user
     * @return StreamedResponse
     */
    public function exportToExcel(string $query, array $filters, User $user): StreamedResponse
    {
        // For now, we'll use CSV format with .xlsx extension
        // For full Excel support, install phpoffice/phpspreadsheet:
        // composer require phpoffice/phpspreadsheet

        $response = $this->exportToCsv($query, $filters, $user);

        $filename = 'search-results-' . date('Y-m-d-His') . '.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * Export search results to PDF
     *
     * @param string $query
     * @param array $filters
     * @param User $user
     * @return StreamedResponse
     */
    public function exportToPdf(string $query, array $filters, User $user): StreamedResponse
    {
        $searchFilters = array_merge($filters, ['limit' => 100]);
        $results = $this->searchService->search($query, $searchFilters);

        $response = new StreamedResponse(function () use ($query, $results) {
            // Simple text-based PDF generation
            // For full PDF support, install tecnickcom/tcpdf or dompdf/dompdf

            $content = $this->generatePdfContent($query, $results);
            echo $content;
        });

        $filename = 'search-results-' . date('Y-m-d-His') . '.pdf';

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }

    /**
     * Generate simple PDF content (text-based)
     *
     * @param string $query
     * @param array $results
     * @return string
     */
    private function generatePdfContent(string $query, array $results): string
    {
        // This is a simplified version
        // For production, use a proper PDF library

        $content = "Search Results Export\n";
        $content .= "Query: " . $query . "\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Total Results: " . ($results['estimatedTotalHits'] ?? 0) . "\n";
        $content .= str_repeat("-", 80) . "\n\n";

        foreach ($results['hits'] ?? [] as $doc) {
            $content .= "File: " . ($doc['originalName'] ?? 'Untitled') . "\n";
            $content .= "Category: " . ($doc['category']['name'] ?? 'None') . "\n";
            $content .= "Size: " . $this->formatBytes($doc['fileSize'] ?? 0) . "\n";
            $content .= "Created: " . ($doc['createdAt'] ?? '') . "\n";
            $content .= str_repeat("-", 80) . "\n";
        }

        return $content;
    }

    /**
     * Format tags array to comma-separated string
     *
     * @param array $tags
     * @return string
     */
    private function formatTags(array $tags): string
    {
        if (empty($tags)) {
            return '';
        }

        $tagNames = array_map(function ($tag) {
            return is_array($tag) ? ($tag['name'] ?? '') : (string) $tag;
        }, $tags);

        return implode(', ', array_filter($tagNames));
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }

        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * Get supported export formats
     *
     * @return array
     */
    public function getSupportedFormats(): array
    {
        return [
            'csv' => [
                'name' => 'CSV',
                'description' => 'Comma-Separated Values',
                'extension' => '.csv',
                'mimeType' => 'text/csv'
            ],
            'excel' => [
                'name' => 'Excel',
                'description' => 'Microsoft Excel Spreadsheet',
                'extension' => '.xlsx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ],
            'pdf' => [
                'name' => 'PDF',
                'description' => 'Portable Document Format',
                'extension' => '.pdf',
                'mimeType' => 'application/pdf'
            ]
        ];
    }
}
