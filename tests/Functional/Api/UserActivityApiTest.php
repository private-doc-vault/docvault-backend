<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * User Activity API Tests
 *
 * Tests cover:
 * - Getting user activity logs
 * - Activity statistics
 * - Filtering and pagination
 * - Admin access control
 */
class UserActivityApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private string $uniqueId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uniqueId = uniqid();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $container = static::getContainer();
            $this->entityManager = $container->get('doctrine.orm.entity_manager');
        }
    }

    private function cleanupTestData(): void
    {
        try {
            $container = static::getContainer();
            $em = $container->get('doctrine.orm.entity_manager');

            // Find all users with emails starting with 'activity'
            $users = $em->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.email LIKE :pattern')
                ->setParameter('pattern', 'activity%')
                ->getQuery()
                ->getResult();

            foreach ($users as $user) {
                $em->remove($user);
            }

            $em->flush();
            $em->clear();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    // ========== Get User Activity Tests ==========

    public function testGetUserActivityRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/activity/user/123');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetOwnUserActivity(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('activityuser' . $this->uniqueId . '@example.com');

        // Create some activity logs for the user
        $this->createTestAuditLog($user, 'document.view', 'Document', 'Viewed document');
        $this->createTestAuditLog($user, 'user.login', 'User', 'User logged in');

        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/activity/user/' . $user->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('activities', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertGreaterThanOrEqual(2, $responseData['total']);
    }

    public function testGetOtherUserActivityRequiresAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('activityuser' . $this->uniqueId . '@example.com');
        $otherUser = $this->createTestUser('activityadmin' . $this->uniqueId . 'b@example.com');

        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/activity/user/' . $otherUser->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewAnyUserActivity(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('activityadmin' . $this->uniqueId . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user = $this->createTestUser('activityuser' . $this->uniqueId . '@example.com');

        $this->createTestAuditLog($user, 'document.upload', 'Document', 'Uploaded document');

        $token = $this->generateJwtToken($admin);

        $client->request(
            'GET',
            '/api/activity/user/' . $user->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('activities', $responseData);
    }

    // ========== Activity Statistics Tests ==========

    public function testGetActivityStats(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('activityuser' . $this->uniqueId . '@example.com');

        // Create various activity logs
        $this->createTestAuditLog($user, 'document.view', 'Document');
        $this->createTestAuditLog($user, 'document.upload', 'Document');
        $this->createTestAuditLog($user, 'user.login', 'User');

        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/activity/stats',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('total_activities', $responseData);
        $this->assertArrayHasKey('by_action', $responseData);
        $this->assertArrayHasKey('recent_count', $responseData);
    }

    // ========== Recent Activity Tests ==========

    public function testGetRecentActivity(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('activityadmin' . $this->uniqueId . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        $this->createTestAuditLog($admin, 'document.view', 'Document', 'Viewed document');

        $token = $this->generateJwtToken($admin);

        $client->request(
            'GET',
            '/api/activity/recent',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('activities', $responseData);
        $this->assertIsArray($responseData['activities']);
    }

    public function testGetRecentActivityWithLimit(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('activityadmin' . $this->uniqueId . '@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        // Create multiple logs
        for ($i = 0; $i < 15; $i++) {
            $this->createTestAuditLog($admin, 'document.view', 'Document', 'Activity ' . $i);
        }

        $token = $this->generateJwtToken($admin);

        $client->request(
            'GET',
            '/api/activity/recent?limit=5',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(5, $responseData['activities']);
    }

    // ========== Filter Tests ==========

    public function testFilterActivitiesByAction(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('activityuser' . $this->uniqueId . '@example.com');

        $this->createTestAuditLog($user, 'document.view', 'Document');
        $this->createTestAuditLog($user, 'document.upload', 'Document');
        $this->createTestAuditLog($user, 'user.login', 'User');

        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/activity/user/' . $user->getId() . '?action=document.view',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        foreach ($responseData['activities'] as $activity) {
            $this->assertEquals('document.view', $activity['action']);
        }
    }

    // ========== Helper Methods ==========

    private function createTestUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $this->initializeServices();
        $passwordHasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTestAuditLog(User $user, string $action, string $resource, ?string $description = null): AuditLog
    {
        $log = new AuditLog();
        $log->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $log->setAction($action);
        $log->setResource($resource);
        $log->setUser($user);
        $log->setDescription($description ?? 'Test activity');
        $log->setIpAddress('127.0.0.1');
        $log->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
