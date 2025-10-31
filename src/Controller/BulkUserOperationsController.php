<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users/bulk', name: 'api_admin_users_bulk_')]
#[IsGranted('ROLE_ADMIN')]
class BulkUserOperationsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Bulk activate users
     */
    #[Route('/activate', name: 'activate', methods: ['POST'])]
    public function bulkActivate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['userIds']) || !is_array($data['userIds']) || empty($data['userIds'])) {
            return new JsonResponse([
                'error' => 'userIds parameter is required and must be a non-empty array'
            ], Response::HTTP_BAD_REQUEST);
        }

        $userIds = $data['userIds'];
        $updated = 0;

        foreach ($userIds as $userId) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);

            if ($user && !$user->isActive()) {
                $user->setIsActive(true);
                $user->setUpdatedAt(new \DateTimeImmutable());
                $updated++;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'updated' => $updated,
            'message' => sprintf('Successfully activated %d user(s)', $updated)
        ]);
    }

    /**
     * Bulk deactivate users
     */
    #[Route('/deactivate', name: 'deactivate', methods: ['POST'])]
    public function bulkDeactivate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['userIds']) || !is_array($data['userIds']) || empty($data['userIds'])) {
            return new JsonResponse([
                'error' => 'userIds parameter is required and must be a non-empty array'
            ], Response::HTTP_BAD_REQUEST);
        }

        $userIds = $data['userIds'];
        $updated = 0;

        foreach ($userIds as $userId) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);

            if ($user && $user->isActive()) {
                $user->setIsActive(false);
                $user->setUpdatedAt(new \DateTimeImmutable());
                $updated++;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'updated' => $updated,
            'message' => sprintf('Successfully deactivated %d user(s)', $updated)
        ]);
    }

    /**
     * Bulk assign roles to users
     */
    #[Route('/assign-roles', name: 'assign_roles', methods: ['POST'])]
    public function bulkAssignRoles(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['userIds']) || !is_array($data['userIds']) || empty($data['userIds'])) {
            return new JsonResponse([
                'error' => 'userIds parameter is required and must be a non-empty array'
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['roles']) || !is_array($data['roles']) || empty($data['roles'])) {
            return new JsonResponse([
                'error' => 'roles parameter is required and must be a non-empty array'
            ], Response::HTTP_BAD_REQUEST);
        }

        $userIds = $data['userIds'];
        $rolesToAdd = $data['roles'];
        $updated = 0;

        foreach ($userIds as $userId) {
            $user = $this->entityManager->getRepository(User::class)->find($userId);

            if ($user) {
                $currentRoles = $user->getRoles();
                $newRoles = array_unique(array_merge($currentRoles, $rolesToAdd));

                if ($currentRoles !== $newRoles) {
                    $user->setRoles($newRoles);
                    $user->setUpdatedAt(new \DateTimeImmutable());
                    $updated++;
                }
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'updated' => $updated,
            'message' => sprintf('Successfully updated roles for %d user(s)', $updated)
        ]);
    }

    /**
     * Bulk delete users
     */
    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['userIds']) || !is_array($data['userIds']) || empty($data['userIds'])) {
            return new JsonResponse([
                'error' => 'userIds parameter is required and must be a non-empty array'
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $userIds = $data['userIds'];
        $deleted = 0;
        $skipped = 0;

        foreach ($userIds as $userId) {
            // Cannot delete self
            if ($userId === $currentUser->getId()) {
                $skipped++;
                continue;
            }

            $user = $this->entityManager->getRepository(User::class)->find($userId);

            if ($user) {
                $this->entityManager->remove($user);
                $deleted++;
            }
        }

        $this->entityManager->flush();

        $response = [
            'success' => true,
            'deleted' => $deleted,
            'message' => sprintf('Successfully deleted %d user(s)', $deleted)
        ];

        if ($skipped > 0) {
            $response['skipped'] = $skipped;
            $response['message'] .= sprintf(', skipped %d user(s)', $skipped);
        }

        return new JsonResponse($response);
    }
}
