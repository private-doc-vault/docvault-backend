<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Category Management API Controller
 *
 * Provides CRUD operations for hierarchical categories
 */
#[Route('/api/categories')]
#[IsGranted('ROLE_USER')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * List all categories
     */
    #[Route('', name: 'api_categories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $categories = $this->entityManager->getRepository(Category::class)
            ->findAll();

        $data = array_map(function (Category $category) {
            return [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'description' => $category->getDescription(),
                'parentId' => $category->getParent()?->getId(),
                'level' => $category->getLevel(),
                'createdAt' => $category->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $categories);

        return new JsonResponse($data);
    }

    /**
     * Create a new category
     */
    #[Route('', name: 'api_categories_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'])) {
            return new JsonResponse([
                'error' => 'Name is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $category = new Category();
        $category->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $category->setName($data['name']);

        // Generate slug from name
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['name']), '-'));
        $category->setSlug($slug);

        if (isset($data['description'])) {
            $category->setDescription($data['description']);
        }

        if (isset($data['parentId'])) {
            $parent = $this->entityManager->getRepository(Category::class)
                ->find($data['parentId']);

            if ($parent) {
                $category->setParent($parent);
            }
        }

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $category->getId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'parentId' => $category->getParent()?->getId(),
            'level' => $category->getLevel(),
            'createdAt' => $category->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
