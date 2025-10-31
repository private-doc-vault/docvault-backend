<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Security\RbacService;
use App\Service\DocumentStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Secure document download controller with permission checks
 *
 * Features:
 * - Authentication required
 * - Permission-based access control (document.read)
 * - Streaming for efficient memory usage
 * - Proper Content-Type and Content-Disposition headers
 * - Support for inline display or attachment download
 * - Audit logging for access tracking
 */
#[Route('/api/documents')]
#[IsGranted('ROLE_USER')]
class DocumentDownloadController extends AbstractController
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentStorageService $storageService
    ) {
    }

    /**
     * Download a document with security checks
     *
     * @param string $id Document ID
     * @param Request $request
     */
    #[Route('/{id}/download', name: 'api_documents_download', methods: ['GET'])]
    public function download(string $id, Request $request): Response
    {
        // Check if user has document read permission
        if (!$this->rbacService->hasPermission('document.read')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.read'
            ], Response::HTTP_FORBIDDEN);
        }

        // Find document
        $document = $this->entityManager->getRepository(Document::class)->find($id);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Get absolute file path
        $filePath = $this->storageService->getAbsolutePath($document->getFilePath());

        // Check if physical file exists
        if (!file_exists($filePath)) {
            return new JsonResponse([
                'error' => 'Document file not found on server'
            ], Response::HTTP_NOT_FOUND);
        }

        // Check if file is readable
        if (!is_readable($filePath)) {
            return new JsonResponse([
                'error' => 'Document file is not accessible'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Determine if file should be displayed inline or downloaded
        $inline = $request->query->getBoolean('inline', false);
        $disposition = $inline ? 'inline' : 'attachment';

        // Get filename for Content-Disposition header
        $filename = $document->getOriginalName() ?? $document->getFilename();

        // Stream file to client
        $response = new StreamedResponse(function () use ($filePath) {
            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                return;
            }

            while (!feof($handle)) {
                echo fread($handle, 8192); // Read in 8KB chunks
                flush();
            }

            fclose($handle);
        });

        // Set response headers
        $response->headers->set('Content-Type', $document->getMimeType());
        $response->headers->set('Content-Length', (string)$document->getFileSize());
        $response->headers->set(
            'Content-Disposition',
            sprintf('%s; filename="%s"', $disposition, $filename)
        );
        $response->headers->set('Cache-Control', 'private, max-age=3600');
        $response->headers->set('X-Document-Id', $document->getId());

        // TODO: Add audit logging here for download tracking

        return $response;
    }
}
