<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/groups', name: 'api_admin_groups_')]
#[IsGranted('ROLE_ADMIN')]
class AdminGroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all user groups
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $repository = $this->entityManager->getRepository(UserGroup::class);

        // Get total count
        $qb = $repository->createQueryBuilder('g');
        $total = (int) $qb->select('COUNT(g.id)')->getQuery()->getSingleScalarResult();

        // Get paginated groups
        $qb = $repository->createQueryBuilder('g')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('g.name', 'ASC');

        // Apply search filter if provided
        $search = $request->query->get('search');
        if ($search) {
            $qb->where('g.name LIKE :search OR g.description LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Apply active filter if provided
        $isActive = $request->query->get('isActive');
        if ($isActive !== null) {
            $qb->andWhere('g.isActive = :isActive')
                ->setParameter('isActive', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $groups = $qb->getQuery()->getResult();

        $groupsData = array_map(
            fn(UserGroup $group) => $this->serializeGroup($group),
            $groups
        );

        return new JsonResponse([
            'groups' => $groupsData,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit)
        ]);
    }

    /**
     * Get group details
     */
    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeGroup($group, true));
    }

    /**
     * Create new group
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'error' => 'Invalid JSON'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['name'])) {
            return new JsonResponse([
                'error' => 'Group name is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate name
        if (!UserGroup::validateName($data['name'])) {
            return new JsonResponse([
                'error' => 'Invalid group name. Must be 2-100 characters.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Generate slug
        $slug = UserGroup::generateSlug($data['name']);

        // Check if slug already exists
        $existingGroup = $this->entityManager->getRepository(UserGroup::class)
            ->findOneBy(['slug' => $slug]);

        if ($existingGroup) {
            return new JsonResponse([
                'error' => 'A group with this name already exists'
            ], Response::HTTP_CONFLICT);
        }

        // Validate permissions if provided
        $permissions = $data['permissions'] ?? [];
        foreach ($permissions as $permission) {
            if (!UserGroup::validatePermission($permission)) {
                return new JsonResponse([
                    'error' => 'Invalid permission format: ' . $permission
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $group->setName($data['name']);
        $group->setSlug($slug);
        $group->setDescription($data['description'] ?? null);
        $group->setPermissions($permissions);
        $group->setCreatedBy($currentUser);
        $group->setCreatedAt(new \DateTimeImmutable());
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeGroup($group, true), Response::HTTP_CREATED);
    }

    /**
     * Update group
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Cannot modify system groups
        if ($group->isSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be modified'
            ], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'error' => 'Invalid JSON'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update name if provided
        if (isset($data['name'])) {
            if (!UserGroup::validateName($data['name'])) {
                return new JsonResponse([
                    'error' => 'Invalid group name. Must be 2-100 characters.'
                ], Response::HTTP_BAD_REQUEST);
            }

            $newSlug = UserGroup::generateSlug($data['name']);

            // Check if new slug conflicts with existing group
            if ($newSlug !== $group->getSlug()) {
                $existingGroup = $this->entityManager->getRepository(UserGroup::class)
                    ->findOneBy(['slug' => $newSlug]);

                if ($existingGroup && $existingGroup->getId() !== $group->getId()) {
                    return new JsonResponse([
                        'error' => 'A group with this name already exists'
                    ], Response::HTTP_CONFLICT);
                }

                $group->setName($data['name']);
                $group->setSlug($newSlug);
            } else {
                $group->setName($data['name']);
            }
        }

        // Update description if provided
        if (isset($data['description'])) {
            $group->setDescription($data['description']);
        }

        // Update permissions if provided
        if (isset($data['permissions'])) {
            foreach ($data['permissions'] as $permission) {
                if (!UserGroup::validatePermission($permission)) {
                    return new JsonResponse([
                        'error' => 'Invalid permission format: ' . $permission
                    ], Response::HTTP_BAD_REQUEST);
                }
            }
            $group->setPermissions($data['permissions']);
        }

        // Update active status if provided
        if (isset($data['isActive'])) {
            $group->setIsActive((bool) $data['isActive']);
        }

        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return new JsonResponse($this->serializeGroup($group, true));
    }

    /**
     * Delete group
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Cannot delete system groups
        if ($group->isSystem()) {
            return new JsonResponse([
                'error' => 'System groups cannot be deleted'
            ], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($group);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Add user to group
     */
    #[Route('/{id}/users', name: 'add_user', methods: ['POST'])]
    public function addUser(string $id, Request $request): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
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

        $user = $this->entityManager->getRepository(User::class)->find($data['userId']);

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        if ($group->hasUser($user)) {
            return new JsonResponse([
                'error' => 'User is already in this group'
            ], Response::HTTP_CONFLICT);
        }

        $group->addUser($user);
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return new JsonResponse($this->serializeGroup($group, true));
    }

    /**
     * Remove user from group
     */
    #[Route('/{id}/users/{userId}', name: 'remove_user', methods: ['DELETE'])]
    public function removeUser(string $id, string $userId): JsonResponse
    {
        $group = $this->entityManager->getRepository(UserGroup::class)->find($id);

        if (!$group) {
            return new JsonResponse([
                'error' => 'Group not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$group->hasUser($user)) {
            return new JsonResponse([
                'error' => 'User is not in this group'
            ], Response::HTTP_NOT_FOUND);
        }

        $group->removeUser($user);
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return new JsonResponse($this->serializeGroup($group, true));
    }

    /**
     * Serialize group entity to array
     */
    private function serializeGroup(UserGroup $group, bool $detailed = false): array
    {
        $data = [
            'id' => $group->getId(),
            'name' => $group->getName(),
            'slug' => $group->getSlug(),
            'description' => $group->getDescription(),
            'permissions' => $group->getPermissions(),
            'isActive' => $group->isActive(),
            'isSystem' => $group->isSystem(),
            'userCount' => $group->getUserCount(),
            'createdAt' => $group->getCreatedAt()?->format('c'),
            'updatedAt' => $group->getUpdatedAt()?->format('c')
        ];

        if ($detailed) {
            $data['createdBy'] = $group->getCreatedBy() ? [
                'id' => $group->getCreatedBy()->getId(),
                'email' => $group->getCreatedBy()->getEmail(),
                'fullName' => $group->getCreatedBy()->getFullName()
            ] : null;

            // Include list of users if detailed
            $data['users'] = array_map(function (User $user) {
                return [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'fullName' => $user->getFullName()
                ];
            }, $group->getUsers()->toArray());
        }

        return $data;
    }
}
