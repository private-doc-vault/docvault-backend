<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Password Reset Service
 *
 * Handles password reset token generation, validation, and password updates
 * with security features like rate limiting and audit logging
 */
class PasswordResetService
{
    private const TOKEN_EXPIRATION_HOURS = 1;
    private const RATE_LIMIT_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator
    ) {
    }

    /**
     * Generate a password reset token for the given email address
     * Returns success=true even for non-existent users for security
     */
    public function generateResetToken(string $email): array
    {
        try {
            // Validate email format
            $violations = $this->validator->validate($email, [
                new Assert\Email(message: 'Please provide a valid email address')
            ]);

            if (count($violations) > 0) {
                return [
                    'success' => false,
                    'error' => 'Please provide a valid email address'
                ];
            }

            // Find user by email
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            // Always return success for security - don't reveal if email exists
            if (!$user) {
                return [
                    'success' => true,
                    'message' => 'If the email address exists in our system, a password reset link has been sent.'
                ];
            }

            // Check rate limiting - only allow one token per user within the time limit
            $existingTokens = $this->entityManager->getRepository(PasswordResetToken::class)
                ->findBy([
                    'user' => $user,
                    'isUsed' => false
                ]);

            $cutoff = new \DateTimeImmutable('-' . self::RATE_LIMIT_MINUTES . ' minutes');
            foreach ($existingTokens as $existingToken) {
                if ($existingToken->getCreatedAt() > $cutoff) {
                    return [
                        'success' => false,
                        'error' => 'Password reset requests are rate limited. Please wait before requesting another reset.'
                    ];
                }
            }

            // Generate new token
            $token = PasswordResetToken::createForUser($user, self::TOKEN_EXPIRATION_HOURS);

            // Save token to database
            $this->entityManager->persist($token);
            $this->entityManager->flush();

            return [
                'success' => true,
                'token' => $token->getToken(),
                'user' => $user,
                'expiresAt' => $token->getExpiresAt(),
                'message' => 'A password reset link has been sent to your email address.'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'An error occurred while processing your request. Please try again.'
            ];
        }
    }

    /**
     * Validate a password reset token
     */
    public function validateToken(string $token): array
    {
        try {
            $resetToken = $this->entityManager->getRepository(PasswordResetToken::class)
                ->findOneBy(['token' => $token]);

            if (!$resetToken) {
                return [
                    'valid' => false,
                    'error' => 'Invalid or expired password reset token.'
                ];
            }

            if ($resetToken->isExpired()) {
                return [
                    'valid' => false,
                    'error' => 'This password reset token has expired. Please request a new one.'
                ];
            }

            if ($resetToken->isUsed()) {
                return [
                    'valid' => false,
                    'error' => 'This password reset token has already been used.'
                ];
            }

            return [
                'valid' => true,
                'email' => $resetToken->getUser()->getEmail(),
                'user' => $resetToken->getUser(),
                'token' => $resetToken,
                'expiresAt' => $resetToken->getExpiresAt()
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'An error occurred while validating the token.'
            ];
        }
    }

    /**
     * Reset password using a valid token
     */
    public function resetPassword(string $token, string $newPassword, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        try {
            // Validate password strength
            $violations = $this->validator->validate($newPassword, [
                new Assert\Length(
                    min: 8,
                    minMessage: 'Password must be at least 8 characters long'
                ),
                new Assert\Regex(
                    pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).*$/',
                    message: 'Password must contain at least one lowercase letter, one uppercase letter, and one number'
                )
            ]);

            if (count($violations) > 0) {
                return [
                    'success' => false,
                    'error' => 'Password does not meet security requirements. It must be at least 8 characters long and contain uppercase, lowercase, and numeric characters.'
                ];
            }

            // Validate token
            $tokenValidation = $this->validateToken($token);
            if (!$tokenValidation['valid']) {
                return [
                    'success' => false,
                    'error' => $tokenValidation['error']
                ];
            }

            $user = $tokenValidation['user'];
            $resetToken = $tokenValidation['token'];

            // Hash new password
            $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);

            // Update user password
            $user->setPassword($hashedPassword);
            $user->setUpdatedAt(new \DateTimeImmutable());

            // Mark token as used
            $resetToken->markAsUsed($ipAddress, $userAgent);

            // Save changes
            $this->entityManager->persist($user);
            $this->entityManager->persist($resetToken);
            $this->entityManager->flush();

            return [
                'success' => true,
                'message' => 'Your password has been successfully reset. You can now log in with your new password.'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'An error occurred while resetting your password. Please try again.'
            ];
        }
    }

    /**
     * Clean up expired password reset tokens
     */
    public function cleanupExpiredTokens(): int
    {
        try {
            $query = $this->entityManager->createQuery(
                'SELECT t FROM App\Entity\PasswordResetToken t WHERE t.expiresAt < :now OR t.isUsed = true'
            );
            $query->setParameter('now', new \DateTimeImmutable());

            $expiredTokens = $query->getResult();

            $count = 0;
            foreach ($expiredTokens as $token) {
                $this->entityManager->remove($token);
                $count++;
            }

            if ($count > 0) {
                $this->entityManager->flush();
            }

            return $count;

        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Get active tokens for a user (for debugging/admin purposes)
     */
    public function getActiveTokensForUser(User $user): array
    {
        return $this->entityManager->getRepository(PasswordResetToken::class)
            ->findBy([
                'user' => $user,
                'isUsed' => false
            ]);
    }

    /**
     * Revoke all active tokens for a user
     */
    public function revokeAllTokensForUser(User $user): int
    {
        $activeTokens = $this->getActiveTokensForUser($user);

        foreach ($activeTokens as $token) {
            $token->setIsUsed(true);
            $this->entityManager->persist($token);
        }

        if (!empty($activeTokens)) {
            $this->entityManager->flush();
        }

        return count($activeTokens);
    }

    /**
     * Get usage statistics for password resets
     */
    public function getUsageStatistics(\DateTimeImmutable $since): array
    {
        try {
            $query = $this->entityManager->createQuery(
                'SELECT COUNT(t.id) as total,
                        SUM(CASE WHEN t.isUsed = true THEN 1 ELSE 0 END) as used,
                        SUM(CASE WHEN t.expiresAt < :now AND t.isUsed = false THEN 1 ELSE 0 END) as expired
                 FROM App\Entity\PasswordResetToken t
                 WHERE t.createdAt >= :since'
            );
            $query->setParameter('since', $since);
            $query->setParameter('now', new \DateTimeImmutable());

            $result = $query->getSingleResult();

            return [
                'total_requested' => (int)$result['total'],
                'successfully_used' => (int)$result['used'],
                'expired_unused' => (int)$result['expired'],
                'success_rate' => $result['total'] > 0 ? round(($result['used'] / $result['total']) * 100, 2) : 0
            ];

        } catch (\Exception) {
            return [
                'total_requested' => 0,
                'successfully_used' => 0,
                'expired_unused' => 0,
                'success_rate' => 0
            ];
        }
    }
}