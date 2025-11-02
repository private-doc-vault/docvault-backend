<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Search view controller
 */
#[IsGranted('ROLE_USER')]
class SearchViewController extends AbstractController
{
    /**
     * Search page
     */
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('search/index.html.twig');
    }
}
