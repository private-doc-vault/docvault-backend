<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\DocumentShare;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_document_sharing_')]
#[IsGranted('ROLE_USER')]
class DocumentSharingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Share a document with another user
     */
    #[Route('/documents/{id}/share', name: 'share_document', methods: ['POST'])]
    public function shareDocument(string $id, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $document = $this->entityManager->getRepository(Document::class)->find($id);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Only the document owner can share it
        if ($document->getUploadedBy()->getId() !== $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'You do not have permission to share this document'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'error' => 'Invalid JSON'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['userId'])) {
            return new JsonResponse([
                'error' => 'userId is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $sharedWithUser = $this->entityManager->getRepository(User::class)->find($data['userId']);

        if (!$sharedWithUser) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Don't allow sharing with yourself
        if ($sharedWithUser->getId() === $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'Cannot share document with yourself'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if already shared
        $existingShare = $this->entityManager->getRepository(DocumentShare::class)
            ->findOneBy([
                'document' => $document,
                'sharedWith' => $sharedWithUser
            ]);

        if ($existingShare) {
            return new JsonResponse([
                'error' => 'Document is already shared with this user'
            ], Response::HTTP_CONFLICT);
        }

        $permissionLevel = $data['permissionLevel'] ?? 'view';

        if (!in_array($permissionLevel, ['view', 'write'], true)) {
            return new JsonResponse([
                'error' => 'Invalid permission level. Must be "view" or "write"'
            ], Response::HTTP_BAD_REQUEST);
        }

        $share = new DocumentShare();
        $share->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $share->setDocument($document);
        $share->setSharedBy($currentUser);
        $share->setSharedWith($sharedWithUser);
        $share->setPermissionLevel($permissionLevel);
        $share->setCreatedAt(new \DateTimeImmutable());

        if (isset($data['note'])) {
            $share->setNote($data['note']);
        }

        if (isset($data['expiresAt'])) {
            try {
                $expiresAt = new \DateTimeImmutable($data['expiresAt']);
                $share->setExpiresAt($expiresAt);
            } catch (\Exception $e) {
                return new JsonResponse([
                    'error' => 'Invalid expiration date format'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $this->entityManager->persist($share);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeShare($share), Response::HTTP_CREATED);
    }

    /**
     * List all shares for a specific document
     */
    #[Route('/documents/{id}/shares', name: 'list_document_shares', methods: ['GET'])]
    public function listDocumentShares(string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $document = $this->entityManager->getRepository(Document::class)->find($id);

        if (!$document) {
            return new JsonResponse([
                'error' => 'Document not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Only the document owner can list shares
        if ($document->getUploadedBy()->getId() !== $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'You do not have permission to view shares for this document'
            ], Response::HTTP_FORBIDDEN);
        }

        $shares = $this->entityManager->getRepository(DocumentShare::class)
            ->findBy(['document' => $document]);

        $sharesData = array_map(
            fn(DocumentShare $share) => $this->serializeShare($share),
            $shares
        );

        return new JsonResponse([
            'shares' => $sharesData,
            'total' => count($sharesData)
        ]);
    }

    /**
     * List all documents shared with the current user
     */
    #[Route('/shares/shared-with-me', name: 'list_shared_with_me', methods: ['GET'])]
    public function listSharedWithMe(): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $shares = $this->entityManager->getRepository(DocumentShare::class)
            ->findBy([
                'sharedWith' => $currentUser,
                'isActive' => true
            ]);

        // Filter out expired shares
        $activeShares = array_filter($shares, fn(DocumentShare $share) => !$share->isExpired());

        $documentsData = array_map(function (DocumentShare $share) {
            $document = $share->getDocument();
            return [
                'id' => $document->getId(),
                'filename' => $document->getFilename(),
                'originalName' => $document->getOriginalName(),
                'mimeType' => $document->getMimeType(),
                'fileSize' => $document->getFileSize(),
                'createdAt' => $document->getCreatedAt()?->format('c'),
                'sharedBy' => [
                    'id' => $share->getSharedBy()->getId(),
                    'email' => $share->getSharedBy()->getEmail(),
                    'fullName' => $share->getSharedBy()->getFullName()
                ],
                'permissionLevel' => $share->getPermissionLevel(),
                'shareId' => $share->getId(),
                'sharedAt' => $share->getCreatedAt()?->format('c')
            ];
        }, $activeShares);

        return new JsonResponse([
            'documents' => array_values($documentsData),
            'total' => count($documentsData)
        ]);
    }

    /**
     * Update a document share
     */
    #[Route('/shares/{id}', name: 'update_share', methods: ['PUT'])]
    public function updateShare(string $id, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $share = $this->entityManager->getRepository(DocumentShare::class)->find($id);

        if (!$share) {
            return new JsonResponse([
                'error' => 'Share not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Only the person who shared the document can update the share
        if ($share->getSharedBy()->getId() !== $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'You do not have permission to update this share'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'error' => 'Invalid JSON'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['permissionLevel'])) {
            if (!in_array($data['permissionLevel'], ['view', 'write'], true)) {
                return new JsonResponse([
                    'error' => 'Invalid permission level. Must be "view" or "write"'
                ], Response::HTTP_BAD_REQUEST);
            }
            $share->setPermissionLevel($data['permissionLevel']);
        }

        if (isset($data['note'])) {
            $share->setNote($data['note']);
        }

        if (isset($data['isActive'])) {
            $share->setIsActive((bool) $data['isActive']);
        }

        if (isset($data['expiresAt'])) {
            try {
                $expiresAt = $data['expiresAt'] ? new \DateTimeImmutable($data['expiresAt']) : null;
                $share->setExpiresAt($expiresAt);
            } catch (\Exception $e) {
                return new JsonResponse([
                    'error' => 'Invalid expiration date format'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeShare($share));
    }

    /**
     * Revoke a document share
     */
    #[Route('/shares/{id}', name: 'revoke_share', methods: ['DELETE'])]
    public function revokeShare(string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $share = $this->entityManager->getRepository(DocumentShare::class)->find($id);

        if (!$share) {
            return new JsonResponse([
                'error' => 'Share not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Only the person who shared the document can revoke the share
        if ($share->getSharedBy()->getId() !== $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'You do not have permission to revoke this share'
            ], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($share);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Serialize share entity to array
     */
    private function serializeShare(DocumentShare $share): array
    {
        return [
            'id' => $share->getId(),
            'document' => [
                'id' => $share->getDocument()->getId(),
                'filename' => $share->getDocument()->getFilename(),
                'originalName' => $share->getDocument()->getOriginalName()
            ],
            'sharedWith' => [
                'id' => $share->getSharedWith()->getId(),
                'email' => $share->getSharedWith()->getEmail(),
                'fullName' => $share->getSharedWith()->getFullName()
            ],
            'sharedBy' => [
                'id' => $share->getSharedBy()->getId(),
                'email' => $share->getSharedBy()->getEmail(),
                'fullName' => $share->getSharedBy()->getFullName()
            ],
            'permissionLevel' => $share->getPermissionLevel(),
            'isActive' => $share->isActive(),
            'expiresAt' => $share->getExpiresAt()?->format('c'),
            'note' => $share->getNote(),
            'accessCount' => $share->getAccessCount(),
            'accessedAt' => $share->getAccessedAt()?->format('c'),
            'createdAt' => $share->getCreatedAt()?->format('c')
        ];
    }
}
