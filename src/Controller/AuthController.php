<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\JwtTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Ramsey\Uuid\Uuid;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private JwtTokenManager $jwtTokenManager,
        private readonly \App\Service\PasswordResetService $passwordResetService
    ) {}

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data)) {
                return new JsonResponse([
                    'error' => 'Request data cannot be empty',
                    'violations' => ['body' => 'Request body is required']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['email', 'password'];
            $violations = [];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty(trim($data[$field]))) {
                    $violations[$field] = ucfirst($field) . ' is required';
                }
            }

            if (!empty($violations)) {
                $errorMessage = 'Validation failed: ' . implode(', ', array_keys($violations));
                return new JsonResponse([
                    'error' => $errorMessage,
                    'violations' => $violations
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse([
                    'error' => 'Invalid email format',
                    'violations' => ['email' => 'Please provide a valid email address']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate password strength (minimum 6 characters for now)
            if (strlen($data['password']) < 6) {
                return new JsonResponse([
                    'error' => 'Password too weak',
                    'violations' => ['password' => 'Password must be at least 6 characters long']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user already exists
            $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                return new JsonResponse([
                    'error' => 'User with this email already exists'
                ], Response::HTTP_CONFLICT);
            }

            // Create new user
            $user = new User();
            $user->setId(Uuid::uuid4()->toString());
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName'] ?? null);
            $user->setLastName($data['lastName'] ?? null);
            $user->setIsActive(true);
            $user->setIsVerified(false); // New users start unverified
            $user->setRoles(['ROLE_USER']);

            // Hash password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
            $user->setPassword($hashedPassword);

            // Set timestamps
            $now = new \DateTimeImmutable();
            $user->setCreatedAt($now);
            $user->setUpdatedAt($now);

            // Validate entity using Symfony validator
            $errors = $this->validator->validate($user);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[$error->getPropertyPath()] = $error->getMessage();
                }

                return new JsonResponse([
                    'error' => 'Validation failed',
                    'violations' => $errorMessages
                ], Response::HTTP_BAD_REQUEST);
            }

            // Persist user
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Return user data (excluding sensitive information)
            return new JsonResponse([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'isActive' => $user->isActive(),
                'isVerified' => $user->isVerified(),
                'createdAt' => $user->getCreatedAt()->format('c')
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Internal server error occurred during registration'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data)) {
                return new JsonResponse([
                    'error' => 'Request data cannot be empty',
                    'violations' => ['body' => 'Request body is required']
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['email', 'password'];
            $violations = [];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty(trim($data[$field]))) {
                    $violations[$field] = ucfirst($field) . ' is required';
                }
            }

            if (!empty($violations)) {
                $errorMessage = 'Missing required field: ' . implode(', ', array_keys($violations));
                return new JsonResponse([
                    'message' => $errorMessage,
                    'violations' => $violations
                ], Response::HTTP_BAD_REQUEST);
            }

            // Find user
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

            if (!$user || !$this->passwordHasher->isPasswordValid($user, $data['password'])) {
                return new JsonResponse([
                    'error' => 'Invalid credentials'
                ], Response::HTTP_UNAUTHORIZED);
            }

            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is deactivated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Generate JWT token
            $token = $this->jwtTokenManager->create($user);
            $refreshToken = $this->jwtTokenManager->createRefreshToken($user);

            return new JsonResponse([
                'token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 3600, // 1 hour
                'user' => [
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'roles' => $user->getRoles(),
                    'isActive' => $user->isActive(),
                    'isVerified' => $user->isVerified()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Internal server error occurred during login'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data['refresh_token'])) {
                return new JsonResponse([
                    'error' => 'Refresh token is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate refresh token and get user
            $user = $this->jwtTokenManager->getUserFromToken($data['refresh_token']);

            if (!$user) {
                return new JsonResponse([
                    'message' => 'Invalid refresh token'
                ], Response::HTTP_UNAUTHORIZED);
            }

            if (!$user->isActive()) {
                return new JsonResponse([
                    'error' => 'Account is deactivated'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Generate new tokens
            $token = $this->jwtTokenManager->create($user);
            $refreshToken = $this->jwtTokenManager->createRefreshToken($user);

            return new JsonResponse([
                'token' => $token,
                'refresh_token' => $refreshToken,
                'expires_in' => 3600
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => 'Invalid refresh token'
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        // For JWT tokens, we can't truly invalidate them server-side without a blacklist
        // This is a placeholder that confirms logout was received
        // In a production system, you might want to implement token blacklisting

        return new JsonResponse([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Request password reset (Step 1)
     */
    #[Route('/password-reset/request', name: 'password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(Request $request): JsonResponse
    {
        try {
            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data['email'])) {
                return new JsonResponse([
                    'error' => 'Email address is required'
                ], Response::HTTP_BAD_REQUEST);
                }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse([
                    'error' => 'Please provide a valid email address'
                ], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->passwordResetService->generateResetToken($data['email']);

            if (!$result['success']) {
                return new JsonResponse([
                    'error' => $result['error']
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            // TODO: Send email notification here
            // For now, we just return success (in production, always return success for security)

            return new JsonResponse([
                'message' => 'If the email address exists in our system, a password reset link has been sent.'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while processing your request'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Validate password reset token (Step 2)
     */
    #[Route('/password-reset/validate/{token}', name: 'password_reset_validate', methods: ['GET'])]
    public function validatePasswordResetToken(string $token): JsonResponse
    {
        try {
            $result = $this->passwordResetService->validateToken($token);

            if (!$result['valid']) {
                return new JsonResponse([
                    'valid' => false,
                    'error' => $result['error']
                ], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'valid' => true,
                'email' => $result['email'],
                'expiresAt' => $result['expiresAt']->format('c')
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'valid' => false,
                'error' => 'An error occurred while validating the token'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Confirm password reset with new password (Step 3)
     */
    #[Route('/password-reset/confirm', name: 'password_reset_confirm', methods: ['POST'])]
    public function confirmPasswordReset(Request $request): JsonResponse
    {
        try {
            // Validate JSON content
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON data provided'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            if (empty($data['token'])) {
                return new JsonResponse([
                    'error' => 'Reset token is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (empty($data['password'])) {
                return new JsonResponse([
                    'error' => 'New password is required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get client IP and User Agent for audit logging
            $ipAddress = $request->getClientIp();
            $userAgent = $request->headers->get('User-Agent');

            $result = $this->passwordResetService->resetPassword(
                $data['token'],
                $data['password'],
                $ipAddress,
                $userAgent
            );

            if (!$result['success']) {
                return new JsonResponse([
                    'error' => $result['error']
                ], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'message' => $result['message']
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'An error occurred while resetting your password'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}