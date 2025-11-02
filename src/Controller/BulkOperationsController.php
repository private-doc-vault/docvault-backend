<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Document;
use App\Entity\DocumentTag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk Operations API Controller
 *
 * Provides bulk operations for documents
 */
#[Route('/api/documents')]
#[IsGranted('ROLE_USER')]
class BulkOperationsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Bulk delete documents
     */
    #[Route('/bulk-delete', name: 'api_documents_bulk_delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return new JsonResponse([
                'error' => 'Document IDs array is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $deleted = 0;
        $failed = 0;
        $errors = [];

        foreach ($data['ids'] as $id) {
            try {
                $document = $this->entityManager->getRepository(Document::class)->find($id);

                if ($document) {
                    $this->entityManager->remove($document);
                    $deleted++;
                } else {
                    $failed++;
                    $errors[] = "Document {$id} not found";
                }
            } catch (\Exception $e) {
                $failed++;
                $errors[] = "Failed to delete {$id}: " . $e->getMessage();
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to commit deletions: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'deleted' => $deleted,
            'failed' => $failed,
            'total' => count($data['ids']),
            'errors' => $errors
        ]);
    }

    /**
     * Bulk assign category to documents
     */
    #[Route('/bulk-assign-category', name: 'api_documents_bulk_assign_category', methods: ['POST'])]
    public function bulkAssignCategory(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return new JsonResponse([
                'error' => 'Document IDs array is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['categoryId'])) {
            return new JsonResponse([
                'error' => 'Category ID is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find category
        $category = $this->entityManager->getRepository(Category::class)
            ->find($data['categoryId']);

        if (!$category) {
            return new JsonResponse([
                'error' => 'Category not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $updated = 0;
        $failed = 0;

        foreach ($data['ids'] as $id) {
            try {
                $document = $this->entityManager->getRepository(Document::class)->find($id);

                if ($document) {
                    $document->setCategory($category);
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to update documents: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($data['ids'])
        ]);
    }

    /**
     * Bulk assign tags to documents
     */
    #[Route('/bulk-assign-tags', name: 'api_documents_bulk_assign_tags', methods: ['POST'])]
    public function bulkAssignTags(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return new JsonResponse([
                'error' => 'Document IDs array is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['tags']) || !is_array($data['tags']) || empty($data['tags'])) {
            return new JsonResponse([
                'error' => 'Tags array is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find or create tags
        $tags = [];
        foreach ($data['tags'] as $tagName) {
            $tag = $this->entityManager->getRepository(DocumentTag::class)
                ->findOneBy(['name' => $tagName]);

            if (!$tag) {
                // Create tag if it doesn't exist
                $tag = new DocumentTag();
                $tag->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
                $tag->setName($tagName);
                $this->entityManager->persist($tag);
            }

            $tags[] = $tag;
        }

        $updated = 0;
        $failed = 0;

        foreach ($data['ids'] as $id) {
            try {
                $document = $this->entityManager->getRepository(Document::class)->find($id);

                if ($document) {
                    foreach ($tags as $tag) {
                        $document->addTag($tag);
                    }
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to update documents: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($data['ids'])
        ]);
    }

    /**
     * Bulk update metadata
     */
    #[Route('/bulk-update-metadata', name: 'api_documents_bulk_update_metadata', methods: ['POST'])]
    public function bulkUpdateMetadata(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            return new JsonResponse([
                'error' => 'Document IDs array is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['metadata']) || !is_array($data['metadata'])) {
            return new JsonResponse([
                'error' => 'Metadata object is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $updated = 0;
        $failed = 0;

        foreach ($data['ids'] as $id) {
            try {
                $document = $this->entityManager->getRepository(Document::class)->find($id);

                if ($document) {
                    $existingMetadata = $document->getMetadata() ?? [];
                    $newMetadata = array_merge($existingMetadata, $data['metadata']);
                    $document->setMetadata($newMetadata);
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to update documents: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($data['ids'])
        ]);
    }
}
