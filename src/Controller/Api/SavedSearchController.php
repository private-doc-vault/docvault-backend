<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SavedSearch;
use App\Entity\SearchHistory;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Saved Search API Controller
 *
 * Manages saved searches and search history for users
 */
#[Route('/api/saved-searches')]
#[IsGranted('ROLE_USER')]
class SavedSearchController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchService $searchService
    ) {
    }

    /**
     * List user's saved searches
     */
    #[Route('', name: 'api_saved_searches_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();

        $savedSearches = $this->entityManager
            ->getRepository(SavedSearch::class)
            ->createQueryBuilder('s')
            ->where('s.user = :user')
            ->orWhere('s.isPublic = :public')
            ->setParameter('user', $user)
            ->setParameter('public', true)
            ->orderBy('s.lastUsedAt', 'DESC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $data = array_map(fn(SavedSearch $s) => [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'query' => $s->getQuery(),
            'filters' => $s->getFilters(),
            'description' => $s->getDescription(),
            'isPublic' => $s->isPublic(),
            'usageCount' => $s->getUsageCount(),
            'isOwner' => $s->getUser() === $user,
            'createdAt' => $s->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $s->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ], $savedSearches);

        return new JsonResponse($data);
    }

    /**
     * Create a new saved search
     */
    #[Route('', name: 'api_saved_searches_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name']) || !isset($data['query'])) {
            return new JsonResponse([
                'error' => 'Name and query are required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $savedSearch = new SavedSearch();
        $savedSearch->setId(Uuid::uuid4()->toString());
        $savedSearch->setName($data['name']);
        $savedSearch->setQuery($data['query']);
        $savedSearch->setFilters($data['filters'] ?? []);
        $savedSearch->setDescription($data['description'] ?? null);
        $savedSearch->setIsPublic($data['isPublic'] ?? false);
        $savedSearch->setUser($this->getUser());

        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $savedSearch->getId(),
            'name' => $savedSearch->getName(),
            'query' => $savedSearch->getQuery(),
            'filters' => $savedSearch->getFilters(),
            'description' => $savedSearch->getDescription(),
            'isPublic' => $savedSearch->isPublic(),
            'createdAt' => $savedSearch->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * Get user's search history
     * NOTE: Must come before /{id} routes to avoid "history" being matched as an ID
     */
    #[Route('/history', name: 'api_search_history_list', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $history = $this->entityManager
            ->getRepository(SearchHistory::class)
            ->createQueryBuilder('h')
            ->where('h.user = :user')
            ->setParameter('user', $this->getUser())
            ->orderBy('h.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = array_map(fn(SearchHistory $h) => [
            'id' => $h->getId(),
            'query' => $h->getQuery(),
            'filters' => $h->getFilters(),
            'resultCount' => $h->getResultCount(),
            'createdAt' => $h->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], $history);

        return new JsonResponse($data);
    }

    /**
     * Clear search history
     * NOTE: Must come before /{id} routes to avoid "history" being matched as an ID
     */
    #[Route('/history/clear', name: 'api_search_history_clear', methods: ['DELETE'])]
    public function clearHistory(): JsonResponse
    {
        $this->entityManager
            ->createQuery('DELETE FROM App\Entity\SearchHistory h WHERE h.user = :user')
            ->setParameter('user', $this->getUser())
            ->execute();

        return new JsonResponse(['message' => 'Search history cleared']);
    }

    /**
     * Get a saved search by ID
     */
    #[Route('/{id}', name: 'api_saved_searches_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $savedSearch = $this->entityManager->find(SavedSearch::class, $id);

        if (!$savedSearch) {
            return new JsonResponse(['error' => 'Saved search not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();

        // Check access: owner or public
        if ($savedSearch->getUser() !== $user && !$savedSearch->isPublic()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'id' => $savedSearch->getId(),
            'name' => $savedSearch->getName(),
            'query' => $savedSearch->getQuery(),
            'filters' => $savedSearch->getFilters(),
            'description' => $savedSearch->getDescription(),
            'isPublic' => $savedSearch->isPublic(),
            'usageCount' => $savedSearch->getUsageCount(),
            'isOwner' => $savedSearch->getUser() === $user,
            'createdAt' => $savedSearch->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $savedSearch->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Execute a saved search
     */
    #[Route('/{id}/execute', name: 'api_saved_searches_execute', methods: ['GET'])]
    public function execute(string $id): JsonResponse
    {
        $savedSearch = $this->entityManager->find(SavedSearch::class, $id);

        if (!$savedSearch) {
            return new JsonResponse(['error' => 'Saved search not found'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();

        // Check access
        if ($savedSearch->getUser() !== $user && !$savedSearch->isPublic()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Record usage
        $savedSearch->recordUsage();
        $this->entityManager->flush();

        // Perform search
        try {
            $results = $this->searchService->search(
                $savedSearch->getQuery(),
                $savedSearch->getFilters()
            );

            return new JsonResponse([
                'savedSearchId' => $savedSearch->getId(),
                'results' => $results
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Search execution failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a saved search
     */
    #[Route('/{id}', name: 'api_saved_searches_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $savedSearch = $this->entityManager->find(SavedSearch::class, $id);

        if (!$savedSearch) {
            return new JsonResponse(['error' => 'Saved search not found'], Response::HTTP_NOT_FOUND);
        }

        // Only owner can update
        if ($savedSearch->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $savedSearch->setName($data['name']);
        }

        if (isset($data['query'])) {
            $savedSearch->setQuery($data['query']);
        }

        if (isset($data['filters'])) {
            $savedSearch->setFilters($data['filters']);
        }

        if (isset($data['description'])) {
            $savedSearch->setDescription($data['description']);
        }

        if (isset($data['isPublic'])) {
            $savedSearch->setIsPublic($data['isPublic']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $savedSearch->getId(),
            'name' => $savedSearch->getName(),
            'query' => $savedSearch->getQuery(),
            'filters' => $savedSearch->getFilters(),
            'description' => $savedSearch->getDescription(),
            'isPublic' => $savedSearch->isPublic(),
        ]);
    }

    /**
     * Delete a saved search
     */
    #[Route('/{id}', name: 'api_saved_searches_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $savedSearch = $this->entityManager->find(SavedSearch::class, $id);

        if (!$savedSearch) {
            return new JsonResponse(['error' => 'Saved search not found'], Response::HTTP_NOT_FOUND);
        }

        // Only owner can delete
        if ($savedSearch->getUser() !== $this->getUser()) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($savedSearch);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Saved search deleted']);
    }
}
