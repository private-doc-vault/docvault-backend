<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Entity\UserGroup;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Role-Based Access Control Service
 *
 * Provides centralized permission checking combining Symfony roles
 * and UserGroup permissions for fine-grained access control
 */
class RbacService
{
    public function __construct(
        private readonly Security $security
    ) {
    }

    /**
     * Check if current user has a specific Symfony role
     * Uses Symfony's built-in role hierarchy
     */
    public function hasRole(string $role): bool
    {
        return $this->security->isGranted($role);
    }

    /**
     * Check if current user has a specific permission through UserGroups
     * Supports wildcard permissions (e.g., 'document.*' matches 'document.read')
     */
    public function hasPermission(string $permission): bool
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // ROLE_ADMIN and ROLE_SUPER_ADMIN have all permissions
        if ($this->isAdmin() || $this->isSuperAdmin()) {
            return true;
        }

        // Check all user groups for the permission
        foreach ($user->getGroups() as $group) {
            if ($this->groupHasPermission($group, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has both the required role and permission
     * This is the main method for comprehensive access control
     */
    public function canAccess(string $permission, string $role = 'ROLE_USER'): bool
    {
        return $this->hasRole($role) && $this->hasPermission($permission);
    }

    /**
     * Check if user can perform any of the specified permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all specified permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get all permissions for current user (from all groups)
     */
    public function getUserPermissions(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        $permissions = [];
        foreach ($user->getGroups() as $group) {
            $permissions = array_merge($permissions, $group->getPermissions());
        }

        return array_unique($permissions);
    }

    /**
     * Get all roles for current user
     */
    public function getUserRoles(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        return $user->getRoles();
    }

    /**
     * Check if current user is admin (has ROLE_ADMIN or higher)
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('ROLE_ADMIN');
    }

    /**
     * Check if current user is super admin (has ROLE_SUPER_ADMIN)
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('ROLE_SUPER_ADMIN');
    }

    /**
     * Get current authenticated user
     */
    public function getCurrentUser(): ?User
    {
        $user = $this->security->getUser();
        return $user instanceof User ? $user : null;
    }

    /**
     * Helper method to check if a group has a specific permission
     * Supports wildcard matching (e.g., 'document.*' matches 'document.read')
     */
    private function groupHasPermission(UserGroup $group, string $permission): bool
    {
        $groupPermissions = $group->getPermissions();

        // Check for exact match
        if (in_array($permission, $groupPermissions, true)) {
            return true;
        }

        // Check for wildcard matches
        foreach ($groupPermissions as $groupPermission) {
            if ($this->matchesWildcard($groupPermission, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a wildcard permission matches a specific permission
     * e.g., 'document.*' matches 'document.read', 'document.write', etc.
     */
    private function matchesWildcard(string $wildcardPermission, string $specificPermission): bool
    {
        if (!str_ends_with($wildcardPermission, '*')) {
            return false;
        }

        $prefix = rtrim($wildcardPermission, '*');
        return str_starts_with($specificPermission, $prefix);
    }
}