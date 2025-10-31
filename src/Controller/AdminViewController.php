<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminViewController extends AbstractController
{
    /**
     * User management page
     */
    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('admin/users.html.twig');
    }
}
