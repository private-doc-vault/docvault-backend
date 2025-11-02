<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Web Security Controller
 *
 * Handles web interface authentication including login and logout
 * with session-based authentication and CSRF protection
 */
class SecurityController extends AbstractController
{
    /**
     * Display login form and handle login submissions
     */
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If user is already authenticated, redirect to dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    /**
     * Logout route - handled by Symfony Security
     */
    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): void
    {
        // This method can be blank - Symfony will intercept this route
        // and handle the logout automatically
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}