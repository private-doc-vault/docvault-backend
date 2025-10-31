<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web Dashboard Controller
 *
 * Handles the main dashboard and other protected web pages
 */
class DashboardController extends AbstractController
{
    /**
     * Main dashboard page
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    /**
     * User profile page
     */
    #[Route('/profile', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function profile(): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    /**
     * Documents page
     */
    #[Route('/documents', name: 'app_documents')]
    #[IsGranted('ROLE_USER')]
    public function documents(): Response
    {
        return $this->render('documents/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    /**
     * Admin page
     */
    #[Route('/admin', name: 'app_admin')]
    #[IsGranted('ROLE_ADMIN')]
    public function admin(): Response
    {
        return $this->render('admin/index.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}