<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/activity', name: 'api_activity_')]
#[IsGranted('ROLE_USER')]
class UserActivityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Get activity logs for a specific user
     */
    #[Route('/user/{id}', name: 'user', methods: ['GET'])]
    public function getUserActivity(string $id, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $targetUser = $this->entityManager->getRepository(User::class)->find($id);

        if (!$targetUser) {
            return new JsonResponse([
                'error' => 'User not found'
            ], Response::HTTP_NOT_FOUND);
        }

        // Users can only view their own activity unless they're admin
        if ($targetUser->getId() !== $currentUser->getId() && !$this->isAdmin($currentUser)) {
            return new JsonResponse([
                'error' => 'You do not have permission to view this user\'s activity'
            ], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        $repository = $this->entityManager->getRepository(AuditLog::class);

        $qb = $repository->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $targetUser)
            ->orderBy('a.createdAt', 'DESC');

        // Apply action filter if provided
        $action = $request->query->get('action');
        if ($action) {
            $qb->andWhere('a.action = :action')
                ->setParameter('action', $action);
        }

        // Apply date range filter if provided
        $from = $request->query->get('from');
        if ($from) {
            try {
                $fromDate = new \DateTimeImmutable($from);
                $qb->andWhere('a.createdAt >= :from')
                    ->setParameter('from', $fromDate);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }

        $to = $request->query->get('to');
        if ($to) {
            try {
                $toDate = new \DateTimeImmutable($to);
                $qb->andWhere('a.createdAt <= :to')
                    ->setParameter('to', $toDate);
            } catch (\Exception $e) {
                // Invalid date format, ignore
            }
        }

        // Get total count
        $totalQb = clone $qb;
        $totalQb->resetDQLPart('orderBy'); // Remove ORDER BY for count query
        $total = (int) $totalQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $qb->setFirstResult($offset)->setMaxResults($limit);
        $activities = $qb->getQuery()->getResult();

        $activitiesData = array_map(
            fn(AuditLog $log) => $this->serializeAuditLog($log),
            $activities
        );

        return new JsonResponse([
            'activities' => $activitiesData,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit)
        ]);
    }

    /**
     * Get activity statistics for current user
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $repository = $this->entityManager->getRepository(AuditLog::class);

        // Get total activities
        $total = $repository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->setParameter('user', $currentUser)
            ->getQuery()
            ->getSingleScalarResult();

        // Get activities by action type
        $byAction = $repository->createQueryBuilder('a')
            ->select('a.action, COUNT(a.id) as count')
            ->where('a.user = :user')
            ->setParameter('user', $currentUser)
            ->groupBy('a.action')
            ->orderBy('count', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Get recent activity count (last 24 hours)
        $yesterday = new \DateTimeImmutable('-24 hours');
        $recentCount = $repository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.user = :user')
            ->andWhere('a.createdAt >= :yesterday')
            ->setParameter('user', $currentUser)
            ->setParameter('yesterday', $yesterday)
            ->getQuery()
            ->getSingleScalarResult();

        return new JsonResponse([
            'total_activities' => (int) $total,
            'by_action' => $byAction,
            'recent_count' => (int) $recentCount,
            'period' => '24h'
        ]);
    }

    /**
     * Get recent activity across all users (admin only)
     */
    #[Route('/recent', name: 'recent', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function getRecentActivity(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $repository = $this->entityManager->getRepository(AuditLog::class);

        $qb = $repository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        // Apply level filter if provided
        $level = $request->query->get('level');
        if ($level) {
            $qb->where('a.level = :level')
                ->setParameter('level', $level);
        }

        $activities = $qb->getQuery()->getResult();

        $activitiesData = array_map(
            fn(AuditLog $log) => $this->serializeAuditLog($log),
            $activities
        );

        return new JsonResponse([
            'activities' => $activitiesData,
            'count' => count($activitiesData)
        ]);
    }

    /**
     * Serialize audit log to array
     */
    private function serializeAuditLog(AuditLog $log): array
    {
        return [
            'id' => $log->getId(),
            'action' => $log->getAction(),
            'resource' => $log->getResource(),
            'resourceId' => $log->getResourceId(),
            'description' => $log->getDescription(),
            'level' => $log->getLevel(),
            'user' => $log->getUser() ? [
                'id' => $log->getUser()->getId(),
                'email' => $log->getUser()->getEmail(),
                'fullName' => $log->getUser()->getFullName()
            ] : null,
            'ipAddress' => $log->getIpAddress(),
            'metadata' => $log->getMetadata(),
            'createdAt' => $log->getCreatedAt()?->format('c')
        ];
    }

    /**
     * Check if user is admin
     */
    private function isAdmin(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true) ||
               in_array('ROLE_SUPER_ADMIN', $roles, true);
    }
}
