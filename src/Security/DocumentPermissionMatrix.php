<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Document;
use App\Entity\DocumentShare;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Document Permission Matrix
 *
 * Centralized service for determining document access permissions.
 * Handles ownership, sharing, and role-based permissions.
 */
class DocumentPermissionMatrix
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Check if user can read a document
     */
    public function canRead(User $user, Document $document): bool
    {
        // Admins can read everything
        if ($this->isAdmin($user)) {
            return true;
        }

        // Owners can read their own documents
        if ($this->isOwner($user, $document)) {
            return true;
        }

        // Check if document is shared with user (view or write permission)
        $share = $this->getActiveShare($user, $document);
        if ($share && ($share->canView() || $share->canEdit())) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can write/edit a document
     */
    public function canWrite(User $user, Document $document): bool
    {
        // Admins can write everything
        if ($this->isAdmin($user)) {
            return true;
        }

        // Owners can write their own documents
        if ($this->isOwner($user, $document)) {
            return true;
        }

        // Check if document is shared with user with write permission
        $share = $this->getActiveShare($user, $document);
        if ($share && $share->canEdit()) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can delete a document
     * Only owners and admins can delete documents
     */
    public function canDelete(User $user, Document $document): bool
    {
        // Admins can delete everything
        if ($this->isAdmin($user)) {
            return true;
        }

        // Only owners can delete their own documents
        // Shared users (even with write permission) cannot delete
        return $this->isOwner($user, $document);
    }

    /**
     * Check if user can share a document
     * Only owners can share documents
     */
    public function canShare(User $user, Document $document): bool
    {
        // Admins can share everything
        if ($this->isAdmin($user)) {
            return true;
        }

        // Only owners can share their documents
        return $this->isOwner($user, $document);
    }

    /**
     * Get permission summary for a user and document
     */
    public function getPermissionSummary(User $user, Document $document): array
    {
        $isOwner = $this->isOwner($user, $document);
        $isAdmin = $this->isAdmin($user);
        $share = $this->getActiveShare($user, $document);

        return [
            'canRead' => $this->canRead($user, $document),
            'canWrite' => $this->canWrite($user, $document),
            'canDelete' => $this->canDelete($user, $document),
            'canShare' => $this->canShare($user, $document),
            'isOwner' => $isOwner,
            'isAdmin' => $isAdmin,
            'sharedPermission' => $share ? $share->getPermissionLevel() : null,
            'shareExpired' => $share ? $share->isExpired() : null
        ];
    }

    /**
     * Get all documents a user can access
     *
     * @return array{owned: Document[], shared: Document[]}
     */
    public function getUserAccessibleDocuments(User $user): array
    {
        $documentRepository = $this->entityManager->getRepository(Document::class);

        // Get owned documents
        $ownedDocuments = $documentRepository->findBy(['uploadedBy' => $user]);

        // Get shared documents
        $shareRepository = $this->entityManager->getRepository(DocumentShare::class);
        $shares = $shareRepository->findBy([
            'sharedWith' => $user,
            'isActive' => true
        ]);

        // Filter out expired shares and extract documents
        $sharedDocuments = [];
        foreach ($shares as $share) {
            if (!$share->isExpired()) {
                $sharedDocuments[] = $share->getDocument();
            }
        }

        return [
            'owned' => $ownedDocuments,
            'shared' => $sharedDocuments
        ];
    }

    /**
     * Check if user is the owner of the document
     */
    private function isOwner(User $user, Document $document): bool
    {
        $owner = $document->getUploadedBy();

        if (!$owner) {
            return false;
        }

        return $owner->getId() === $user->getId();
    }

    /**
     * Check if user is an admin
     */
    private function isAdmin(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true) ||
               in_array('ROLE_SUPER_ADMIN', $roles, true);
    }

    /**
     * Get active (non-expired, active) share for user and document
     */
    private function getActiveShare(User $user, Document $document): ?DocumentShare
    {
        $shareRepository = $this->entityManager->getRepository(DocumentShare::class);

        $share = $shareRepository->findOneBy([
            'document' => $document,
            'sharedWith' => $user
        ]);

        if (!$share) {
            return null;
        }

        // Check if share is active and not expired
        if (!$share->isActive() || $share->isExpired()) {
            return null;
        }

        return $share;
    }
}
