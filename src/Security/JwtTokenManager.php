<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * JWT Token Manager wrapper for enhanced token operations
 * 
 * Provides additional functionality on top of LexikJWTAuthenticationBundle
 * for token validation, parsing, and user extraction
 */
class JwtTokenManager
{
    public function __construct(
        private readonly JWTTokenManagerInterface $lexikJwtManager,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Create a JWT token for the given user
     */
    public function createToken(User $user): string
    {
        return $this->lexikJwtManager->create($user);
    }

    /**
     * Create a JWT token for the given user (alias for createToken)
     */
    public function create(User $user): string
    {
        return $this->createToken($user);
    }

    /**
     * Create a refresh token for the given user
     * For now, this is the same as a regular token but could be different in production
     */
    public function createRefreshToken(User $user): string
    {
        return $this->createToken($user);
    }

    /**
     * Parse a JWT token and return its payload
     * 
     * @throws \InvalidArgumentException if token is invalid
     */
    public function parseToken(string $token): array
    {
        // Use the actual implementation class directly for parse method
        if (method_exists($this->lexikJwtManager, 'parse')) {
            return $this->lexikJwtManager->parse($token);
        }
        
        // Fallback if method doesn't exist - shouldn't happen in real usage
        throw new \BadMethodCallException('Parse method not available');
    }

    /**
     * Validate if a JWT token is valid
     */
    public function validateToken(string $token): bool
    {
        try {
            $this->lexikJwtManager->parse($token);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Extract username from JWT token
     */
    public function getUsernameFromToken(string $token): ?string
    {
        try {
            $payload = $this->lexikJwtManager->parse($token);
            return $payload['username'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Extract roles from JWT token
     */
    public function getRolesFromToken(string $token): array
    {
        try {
            $payload = $this->lexikJwtManager->parse($token);
            return $payload['roles'] ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check if JWT token is expired
     */
    public function isTokenExpired(string $token): bool
    {
        try {
            $payload = $this->lexikJwtManager->parse($token);
            $expirationTime = $payload['exp'] ?? 0;
            return time() >= $expirationTime;
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * Refresh a JWT token by creating a new one
     */
    public function refreshToken(string $oldToken, User $user): string
    {
        // Validate the old token first
        $this->lexikJwtManager->parse($oldToken);

        // Create a new token for the user
        return $this->lexikJwtManager->create($user);
    }

    /**
     * Get user from JWT token
     * Parses the token and loads the user from database
     */
    public function getUserFromToken(string $token): ?User
    {
        try {
            $payload = $this->lexikJwtManager->parse($token);
            $username = $payload['username'] ?? null;
            $email = $payload['email'] ?? null;

            // Try both username and email fields
            $identifier = $username ?? $email;

            if (!$identifier) {
                return null;
            }

            // Load user from database by email (username is email in our case)
            return $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $identifier]);

        } catch (\Exception) {
            return null;
        }
    }
}