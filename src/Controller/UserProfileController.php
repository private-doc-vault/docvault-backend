<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/profile', name: 'api_profile_')]
class UserProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * Get current user's profile information
     */
    #[Route('', name: 'get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getProfile(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Check if user is active
            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is deactivated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Return user profile data (excluding sensitive information)
            return new JsonResponse([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'roles' => $user->getRoles(),
                'isActive' => $user->isActive(),
                'isVerified' => $user->isVerified(),
                'preferences' => $user->getPreferences() ?? [],
                'createdAt' => $user->getCreatedAt()->format('c'),
                'updatedAt' => $user->getUpdatedAt()->format('c')
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while retrieving your profile'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update current user's profile information
     */
    #[Route('', name: 'update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Check if user is active
            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is deactivated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data)) {
                return new JsonResponse([
                    'error' => 'No data provided for update'
                ], Response::HTTP_BAD_REQUEST);
            }

            $updated = false;

            // Update firstName if provided
            if (isset($data['firstName'])) {
                $user->setFirstName($data['firstName']);
                $updated = true;
            }

            // Update lastName if provided
            if (isset($data['lastName'])) {
                $user->setLastName($data['lastName']);
                $updated = true;
            }

            // Update preferences if provided
            if (isset($data['preferences']) && is_array($data['preferences'])) {
                $currentPreferences = $user->getPreferences() ?? [];
                $newPreferences = array_merge($currentPreferences, $data['preferences']);
                $user->setPreferences($newPreferences);
                $updated = true;
            }

            if (!$updated) {
                return new JsonResponse([
                    'error' => 'No valid fields provided for update'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update timestamp
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Persist changes
            $this->entityManager->flush();

            // Create audit log entry
            $auditLog = AuditLog::createForUser(
                'profile_updated',
                $user,
                $user,
                'User profile information updated'
            );
            $auditLog->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
            // Add additional metadata
            $auditLog->setMetadata([
                'updated_fields' => array_keys($data),
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);
            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();

            return new JsonResponse([
                'message' => 'Profile successfully updated'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while updating your profile'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Change current user's password
     */
    #[Route('/change-password', name: 'change_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Check if user is active
            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is deactivated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['currentPassword', 'newPassword', 'confirmPassword'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return new JsonResponse([
                        'error' => 'All password fields are required'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Verify current password
            if (!$this->passwordHasher->isPasswordValid($user, $data['currentPassword'])) {
                return new JsonResponse([
                    'error' => 'Current password is incorrect'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate new password confirmation
            if ($data['newPassword'] !== $data['confirmPassword']) {
                return new JsonResponse([
                    'error' => 'New passwords do not match'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate new password strength (minimum 6 characters)
            if (strlen($data['newPassword']) < 6) {
                return new JsonResponse([
                    'error' => 'New password must be at least 6 characters long'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Hash and set new password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['newPassword']);
            $user->setPassword($hashedPassword);
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Persist changes
            $this->entityManager->flush();

            // Create audit log entry
            $auditLog = AuditLog::createForUser(
                'password_changed',
                $user,
                $user,
                'User password changed'
            );
            $auditLog->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
            // Add additional metadata
            $auditLog->setMetadata([
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);
            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();

            return new JsonResponse([
                'message' => 'Password successfully changed'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while changing your password'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Deactivate current user's account
     */
    #[Route('/deactivate', name: 'deactivate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function deactivateAccount(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            // Check if user is already deactivated
            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is already deactivated'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required password
            if (empty($data['password'])) {
                return new JsonResponse([
                    'error' => 'Password is required to deactivate account'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Verify password
            if (!$this->passwordHasher->isPasswordValid($user, $data['password'])) {
                return new JsonResponse([
                    'error' => 'Password is incorrect'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Deactivate account
            $user->setIsActive(false);
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Persist changes
            $this->entityManager->flush();

            // Create audit log entry
            $auditLog = AuditLog::createForUser(
                'account_deactivated',
                $user,
                $user,
                'User account deactivated'
            );
            $auditLog->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
            // Add additional metadata
            $auditLog->setMetadata([
                'reason' => $data['reason'] ?? 'User requested account deactivation',
                'ip_address' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent')
            ]);
            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();

            return new JsonResponse([
                'message' => 'Account successfully deactivated'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while deactivating your account'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}