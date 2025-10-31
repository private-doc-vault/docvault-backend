<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Document views and pages
 */
#[IsGranted('ROLE_USER')]
class DocumentsViewController extends AbstractController
{
    /**
     * Document upload page
     */
    #[Route('/documents/upload', name: 'app_documents_upload', methods: ['GET'])]
    public function upload(): Response
    {
        return $this->render('documents/upload.html.twig');
    }

    /**
     * Documents browser page
     */
    #[Route('/documents', name: 'app_documents', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('documents/index.html.twig');
    }

    /**
     * Document detail page
     */
    #[Route('/documents/{id}', name: 'app_documents_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->render('documents/show.html.twig', [
            'documentId' => $id
        ]);
    }
}
