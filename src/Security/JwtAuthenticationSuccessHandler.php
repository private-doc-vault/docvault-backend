<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Custom JWT authentication success handler
 *
 * Returns JWT token with refresh token and expires_in information
 * as expected by the API contract
 */
class JwtAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private EntityManagerInterface $entityManager
    ) {}

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        // Generate JWT token
        $jwtToken = $this->jwtManager->create($user);

        // Generate refresh token (for now, same as access token)
        $refreshToken = $this->jwtManager->create($user);

        // Update last login timestamp
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Return token data in expected format
        return new JsonResponse([
            'token' => $jwtToken,
            'refresh_token' => $refreshToken,
            'expires_in' => 3600 // 1 hour in seconds
        ], Response::HTTP_OK);
    }
}