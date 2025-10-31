<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Security\RbacService;
use App\Security\Voter\PermissionVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Role and Permission Management endpoints
 *
 * Provides RBAC administration functionality for managing
 * user roles, groups, and permissions
 */
#[Route('/api/roles', name: 'api_roles_')]
class RoleController extends AbstractController
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all available user groups and their permissions
     */
    #[Route('/groups', name: 'groups_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listGroups(): JsonResponse
    {
        $groups = $this->entityManager->getRepository(UserGroup::class)->findAll();

        $groupsData = array_map(function (UserGroup $group) {
            return [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'slug' => $group->getSlug(),
                'description' => $group->getDescription(),
                'permissions' => $group->getPermissions(),
                'userCount' => $group->getUsers()->count(),
                'isActive' => $group->isActive(),
                'isSystem' => $group->isSystem(),
                'createdAt' => $group->getCreatedAt()->format('c'),
                'updatedAt' => $group->getUpdatedAt()->format('c')
            ];
        }, $groups);

        return new JsonResponse([
            'groups' => $groupsData,
            'total' => count($groupsData)
        ]);
    }

    /**
     * Create a new user group
     */
    #[Route('/groups', name: 'groups_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createGroup(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data || !isset($data['name'])) {
            return new JsonResponse([
                'error' => 'Invalid request. Group name is required.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if group name already exists
        $existingGroup = $this->entityManager->getRepository(UserGroup::class)
            ->findOneBy(['name' => $data['name']]);

        if ($existingGroup) {
            return new JsonResponse([
                'error' => 'Group with this name already exists'
            ], Response::HTTP_CONFLICT);
        }

        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $group->setName($data['name']);
        $group->setSlug(UserGroup::generateSlug($data['name']));
        $group->setDescription($data['description'] ?? null);
        $group->setPermissions($data['permissions'] ?? []);
        $group->setIsActive($data['isActive'] ?? true);
        $group->setCreatedAt(new \DateTimeImmutable());
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return new JsonResponse([
            'group' => [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'slug' => $group->getSlug(),
                'description' => $group->getDescription(),
                'permissions' => $group->getPermissions(),
                'userCount' => 0,
                'isActive' => $group->isActive(),
                'isSystem' => $group->isSystem(),
                'createdAt' => $group->getCreatedAt()->format('c'),
                'updatedAt' => $group->getUpdatedAt()->format('c')
            ],
            'message' => 'Group created successfully'
        ], Response::HTTP_CREATED);
    }

    /**
     * Update a user group
     */
    #[Route('/groups/{id}', name: 'groups_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateGroup(string $id, Request $request): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Prevent modification of system groups
        if ($group->isSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be modified'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            // Check if new name conflicts with existing group
            $existingGroup = $this->entityManager->getRepository(UserGroup::class)
                ->findOneBy(['name' => $data['name']]);

            if ($existingGroup && $existingGroup->getId() !== $group->getId()) {
                return new JsonResponse([
                    'error' => 'Group with this name already exists'
                ], Response::HTTP_CONFLICT);
            }

            $group->setName($data['name']);
            $group->setSlug(UserGroup::generateSlug($data['name']));
        }

        if (isset($data['description'])) {
            $group->setDescription($data['description']);
        }

        if (isset($data['permissions'])) {
            $group->setPermissions($data['permissions']);
        }

        if (isset($data['isActive'])) {
            $group->setIsActive($data['isActive']);
        }

        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return new JsonResponse([
            'group' => [
                'id' => $group->getId(),
                'name' => $group->getName(),
                'slug' => $group->getSlug(),
                'description' => $group->getDescription(),
                'permissions' => $group->getPermissions(),
                'userCount' => $group->getUsers()->count(),
                'isActive' => $group->isActive(),
                'isSystem' => $group->isSystem(),
                'createdAt' => $group->getCreatedAt()->format('c'),
                'updatedAt' => $group->getUpdatedAt()->format('c')
            ],
            'message' => 'Group updated successfully'
        ]);
    }

    /**
     * Delete a user group
     */
    #[Route('/groups/{id}', name: 'groups_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function deleteGroup(string $id): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Prevent deletion of system groups
        if ($group->isSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be deleted'
            ], Response::HTTP_FORBIDDEN);
        }

        // Check if group has users
        if ($group->getUsers()->count() > 0) {
            return new JsonResponse([
                'error' => 'Cannot delete group with assigned users. Remove users first.'
            ], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($group);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Group deleted successfully',
            'groupId' => $id
        ]);
    }

    /**
     * Assign user to group
     */
    #[Route('/groups/{groupId}/users/{userId}', name: 'assign_user', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function assignUserToGroup(string $groupId, string $userId): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$group) {
            return new JsonResponse(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if user is already in group
        if ($user->getGroups()->contains($group)) {
            return new JsonResponse([
                'error' => 'User is already assigned to this group'
            ], Response::HTTP_CONFLICT);
        }

        $user->getGroups()->add($group);
        $group->getUsers()->add($user);

        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'User assigned to group successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'groups' => array_map(fn($g) => ['id' => $g->getId(), 'name' => $g->getName()], $user->getGroups()->toArray())
            ]
        ]);
    }

    /**
     * Remove user from group
     */
    #[Route('/groups/{groupId}/users/{userId}', name: 'remove_user', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function removeUserFromGroup(string $groupId, string $userId): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$group || !$user) {
            return new JsonResponse(['error' => 'Group or user not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if user is in the group
        if (!$user->getGroups()->contains($group)) {
            return new JsonResponse([
                'error' => 'User is not assigned to this group'
            ], Response::HTTP_CONFLICT);
        }

        $user->getGroups()->removeElement($group);
        $group->getUsers()->removeElement($user);

        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'User removed from group successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'groups' => array_map(fn($g) => ['id' => $g->getId(), 'name' => $g->getName()], $user->getGroups()->toArray())
            ]
        ]);
    }

    /**
     * Get current user's permissions and roles
     */
    #[Route('/me', name: 'current_user_permissions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getCurrentUserPermissions(): JsonResponse
    {
        $currentUser = $this->rbacService->getCurrentUser();

        if (!$currentUser) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'user' => [
                'id' => $currentUser->getId(),
                'email' => $currentUser->getEmail(),
                'roles' => $this->rbacService->getUserRoles(),
                'permissions' => $this->rbacService->getUserPermissions(),
                'groups' => array_map(function ($group) {
                    return [
                        'id' => $group->getId(),
                        'name' => $group->getName(),
                        'permissions' => $group->getPermissions()
                    ];
                }, $currentUser->getGroups()->toArray()),
                'isAdmin' => $this->rbacService->isAdmin(),
                'isSuperAdmin' => $this->rbacService->isSuperAdmin()
            ]
        ]);
    }

    /**
     * List all available permissions in the system
     */
    #[Route('/permissions', name: 'permissions_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listAvailablePermissions(): JsonResponse
    {
        // In a real system, these could be dynamically discovered
        // or stored in configuration/database
        $permissions = [
            'document' => [
                'document.read' => 'Read documents',
                'document.write' => 'Create and edit documents',
                'document.delete' => 'Delete documents',
                'document.*' => 'All document operations'
            ],
            'user' => [
                'user.read' => 'View user information',
                'user.manage' => 'Manage users and groups',
                'user.*' => 'All user operations'
            ],
            'admin' => [
                'admin.stats' => 'View system statistics',
                'admin.config' => 'Manage system configuration',
                'admin.*' => 'All admin operations'
            ]
        ];

        return new JsonResponse([
            'permissions' => $permissions,
            'categories' => array_keys($permissions)
        ]);
    }
}