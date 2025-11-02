<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DocumentTag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tag Management API Controller
 *
 * Provides CRUD operations for document tags
 */
#[Route('/api/tags')]
#[IsGranted('ROLE_USER')]
class TagController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all tags
     */
    #[Route('', name: 'api_tags_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $tags = $this->entityManager->getRepository(DocumentTag::class)
            ->findAll();

        $data = array_map(function (DocumentTag $tag) {
            return [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'color' => $tag->getColor(),
                'usageCount' => $tag->getUsageCount(),
                'createdAt' => $tag->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $tags);

        return new JsonResponse($data);
    }

    /**
     * Create a new tag
     */
    #[Route('', name: 'api_tags_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'])) {
            return new JsonResponse([
                'error' => 'Name is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $tag = new DocumentTag();
        $tag->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $tag->setName($data['name']);

        // Generate slug from name
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']), '-'));
        $tag->setSlug($slug);

        if (isset($data['color'])) {
            $tag->setColor($data['color']);
        }

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'usageCount' => $tag->getUsageCount(),
            'createdAt' => $tag->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
