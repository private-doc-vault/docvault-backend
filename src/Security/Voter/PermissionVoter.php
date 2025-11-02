<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\RbacService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permission Voter for general RBAC permission checking
 *
 * Handles authorization for any permission string using UserGroup permissions
 * Format: PERMISSION_<permission.name> (e.g., PERMISSION_document.read)
 */
class PermissionVoter extends Voter
{
    private const PERMISSION_PREFIX = 'PERMISSION_';

    public function __construct(
        private readonly RbacService $rbacService
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Check if this is a permission attribute
        return str_starts_with($attribute, self::PERMISSION_PREFIX);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Anonymous users have no permissions
        if (!$user instanceof User) {
            return false;
        }

        // Extract permission name from attribute
        $permission = substr($attribute, strlen(self::PERMISSION_PREFIX));

        // Super admins can do anything
        if ($this->rbacService->isSuperAdmin()) {
            return true;
        }

        // Check if user has the specific permission
        return $this->rbacService->hasPermission($permission);
    }

    /**
     * Helper method to create permission attributes
     */
    public static function createAttribute(string $permission): string
    {
        return self::PERMISSION_PREFIX . $permission;
    }
}