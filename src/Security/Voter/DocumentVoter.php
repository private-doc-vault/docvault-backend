<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\User;
use App\Security\DocumentPermissionMatrix;
use App\Security\RbacService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Document Voter for RBAC permission checking
 *
 * Handles authorization for document operations using ownership,
 * sharing permissions, and role-based access control
 */
class DocumentVoter extends Voter
{
    public const READ = 'DOCUMENT_READ';
    public const WRITE = 'DOCUMENT_WRITE';
    public const DELETE = 'DOCUMENT_DELETE';
    public const CREATE = 'DOCUMENT_CREATE';
    public const SHARE = 'DOCUMENT_SHARE';

    public function __construct(
        private readonly RbacService $rbacService,
        private readonly DocumentPermissionMatrix $permissionMatrix
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Check if this is a document operation we handle
        $supportedAttributes = [self::READ, self::WRITE, self::DELETE, self::CREATE, self::SHARE];

        if (!in_array($attribute, $supportedAttributes)) {
            return false;
        }

        // For CREATE, we don't need a specific document
        if ($attribute === self::CREATE) {
            return true;
        }

        // For other operations, we need a Document or document ID
        return $subject instanceof Document || is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Anonymous users can't access documents
        if (!$user instanceof User) {
            return false;
        }

        // Ensure we have a Document entity for non-CREATE operations
        if ($attribute !== self::CREATE && !$subject instanceof Document) {
            return false;
        }

        // Check permission based on attribute using the permission matrix
        return match ($attribute) {
            self::READ => $this->permissionMatrix->canRead($user, $subject),
            self::WRITE => $this->permissionMatrix->canWrite($user, $subject),
            self::DELETE => $this->permissionMatrix->canDelete($user, $subject),
            self::SHARE => $this->permissionMatrix->canShare($user, $subject),
            self::CREATE => $this->canCreate($user),
            default => false,
        };
    }

    private function canCreate(User $user): bool
    {
        // Check if user has document create/write permission through groups
        // OR is an admin
        if ($this->rbacService->isAdmin() || $this->rbacService->isSuperAdmin()) {
            return true;
        }

        return $this->rbacService->hasPermission('document.write') ||
               $this->rbacService->hasPermission('document.create');
    }
}