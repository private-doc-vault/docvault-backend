<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/users', name: 'api_admin_users_')]
#[IsGranted('ROLE_ADMIN')]
class AdminUserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $repository = $this->entityManager->getRepository(User::class);

        // Get total count
        $qb = $repository->createQueryBuilder('u');
        $total = (int) $qb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        // Get paginated users
        $qb = $repository->createQueryBuilder('u')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('u.createdAt', 'DESC');

        // Apply search filter if provided
        $search = $request->query->get('search');
        if ($search) {
            $qb->where('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Apply role filter if provided
        $role = $request->query->get('role');
        if ($role) {
            $qb->andWhere('u.roles LIKE :role')
                ->setParameter('role', '%"' . $role . '"%');
        }

        // Apply active filter if provided
        $isActive = $request->query->get('isActive');
        if ($isActive !== null) {
            $qb->andWhere('u.isActive = :isActive')
                ->setParameter('isActive', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $users = $qb->getQuery()->getResult();

        $usersData = array_map(
            fn(User $user) => $this->serializeUser($user),
            $users
        );

        return new JsonResponse([
            'users' => $usersData,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit)
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeUser($user, true));
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new JsonResponse([
                'error' => 'Invalid JSON'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Update user properties
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }

        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }

        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }

        if (isset($data['roles'])) {
            $user->setRoles($data['roles']);
        }

        if (isset($data['isActive'])) {
            $user->setIsActive((bool) $data['isActive']);
        }

        if (isset($data['isVerified'])) {
            $user->setIsVerified((bool) $data['isVerified']);
        }

        if (isset($data['password'])) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
            $user->setPassword($hashedPassword);
        }

        if (isset($data['preferences'])) {
            $user->setPreferences($data['preferences']);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());

        // Validate user
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }

            return new JsonResponse([
                'error' => 'Validation failed',
                'details' => $errorMessages
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeUser($user, true));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Prevent self-deletion
        if ($user->getId() === $currentUser->getId()) {
            return new JsonResponse([
                'error' => 'Cannot delete your own account'
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'User deleted successfully'
        ], Response::HTTP_NO_CONTENT);
    }

    /**
     * Serialize user entity to array
     */
    private function serializeUser(User $user, bool $detailed = false): array
    {
        $data = [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
            'roles' => $user->getRoles(),
            'isActive' => $user->isActive(),
            'isVerified' => $user->isVerified(),
            'createdAt' => $user->getCreatedAt()?->format('c'),
            'updatedAt' => $user->getUpdatedAt()?->format('c')
        ];

        if ($detailed) {
            $data['lastLoginAt'] = $user->getLastLoginAt()?->format('c');
            $data['emailVerifiedAt'] = $user->getEmailVerifiedAt()?->format('c');
            $data['preferences'] = $user->getPreferences();
            $data['documentCount'] = $user->getDocuments()->count();
            $data['groupCount'] = $user->getGroups()->count();
        }

        return $data;
    }
}
