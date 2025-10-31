<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\RbacService;
use App\Security\Voter\DocumentVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Document management endpoints - RBAC Demo
 *
 * Demonstrates RBAC implementation for document operations
 * NOTE: This is a demo controller with mock data
 */
#[Route('/api/rbac-demo/documents', name: 'api_rbac_demo_documents_')]
#[IsGranted('ROLE_USER')]
class RbacDemoDocumentController extends AbstractController
{
    public function __construct(
        private readonly RbacService $rbacService
    ) {
    }

    /**
     * List all documents (requires document.read permission)
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // Check if user has document read permission
        if (!$this->rbacService->hasPermission('document.read')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.read'
            ], Response::HTTP_FORBIDDEN);
        }

        // In a real application, this would fetch documents from database
        $documents = [
            [
                'id' => '1',
                'title' => 'Sample Document 1',
                'content' => 'This is a sample document.',
                'createdAt' => '2024-01-01T00:00:00Z'
            ],
            [
                'id' => '2',
                'title' => 'Sample Document 2',
                'content' => 'This is another sample document.',
                'createdAt' => '2024-01-02T00:00:00Z'
            ]
        ];

        return new JsonResponse([
            'documents' => $documents,
            'total' => count($documents)
        ]);
    }

    /**
     * Get specific document (requires document.read permission)
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        // Check if user has document read permission
        if (!$this->rbacService->hasPermission('document.read')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.read'
            ], Response::HTTP_FORBIDDEN);
        }

        // In a real application, this would fetch the document from database
        // For testing purposes, return 404 for all-zeros UUID
        if ($id === '00000000-0000-0000-0000-000000000000') {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $document = [
            'id' => $id,
            'title' => 'Sample Document ' . $id,
            'content' => 'This is the content of document ' . $id,
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => '2024-01-01T12:00:00Z'
        ];

        return new JsonResponse(['document' => $document]);
    }

    /**
     * Create new document (requires document.write permission)
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // Check if user has document write permission
        if (!$this->rbacService->hasPermission('document.write')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.write'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['title'])) {
            return new JsonResponse([
                'error' => 'Invalid request. Title is required.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // In a real application, this would save to database
        $document = [
            'id' => uniqid(),
            'title' => $data['title'],
            'content' => $data['content'] ?? '',
            'createdAt' => (new \DateTimeImmutable())->format('c'),
            'updatedAt' => (new \DateTimeImmutable())->format('c')
        ];

        return new JsonResponse([
            'document' => $document,
            'message' => 'Document created successfully'
        ], Response::HTTP_CREATED);
    }

    /**
     * Update document (requires document.write permission)
     */
    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        // Check if user has document write permission
        if (!$this->rbacService->hasPermission('document.write')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.write'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        // In a real application, this would update the document in database
        $document = [
            'id' => $id,
            'title' => $data['title'] ?? 'Updated Document ' . $id,
            'content' => $data['content'] ?? 'Updated content',
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => (new \DateTimeImmutable())->format('c')
        ];

        return new JsonResponse([
            'document' => $document,
            'message' => 'Document updated successfully'
        ]);
    }

    /**
     * Delete document (requires document.delete permission)
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        // Check if user has document delete permission
        if (!$this->rbacService->hasPermission('document.delete')) {
            return new JsonResponse([
                'error' => 'Access denied. Required permission: document.delete'
            ], Response::HTTP_FORBIDDEN);
        }

        // In a real application, this would delete the document from database
        return new JsonResponse([
            'message' => 'Document deleted successfully',
            'documentId' => $id
        ]);
    }
}