<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/user', name: 'api_user_')]
class UserController extends AbstractController
{
    #[Route('/profile', name: 'profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function profile(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse([
                'error' => 'User not authenticated'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'isActive' => $user->isActive(),
            'isVerified' => $user->isVerified(),
            'preferences' => $user->getPreferences(),
            'createdAt' => $user->getCreatedAt()->format('c'),
            'updatedAt' => $user->getUpdatedAt()->format('c')
        ]);
    }
}