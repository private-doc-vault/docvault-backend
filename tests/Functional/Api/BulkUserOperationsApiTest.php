<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bulk User Operations API Tests
 *
 * Tests cover:
 * - Bulk user activation/deactivation
 * - Bulk role assignment
 * - Bulk user deletion
 * - Permission checks
 */
class BulkUserOperationsApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
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

            $testEmailPatterns = ['bulkadmin@', 'bulkuser', 'bulktest'];

            foreach ($testEmailPatterns as $pattern) {
                $users = $em->getRepository(User::class)
                    ->createQueryBuilder('u')
                    ->where('u.email LIKE :pattern')
                    ->setParameter('pattern', '%' . $pattern . '%')
                    ->getQuery()
                    ->getResult();

                foreach ($users as $user) {
                    $em->remove($user);
                }
            }

            $em->flush();
            $em->clear();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    // ========== Bulk Activation/Deactivation Tests ==========

    public function testBulkActivateUsersRequiresAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('bulkuser1@example.com');
        $token = $this->generateJwtToken($user);

        $client->request(
            'POST',
            '/api/admin/users/bulk/activate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$user->getId()]])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testBulkActivateUsers(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user1 = $this->createTestUser('bulkuser1@example.com');
        $user2 = $this->createTestUser('bulkuser2@example.com');

        // Deactivate users first
        $user1->setIsActive(false);
        $user2->setIsActive(false);
        $this->entityManager->flush();

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/activate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$user1->getId(), $user2->getId()]])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $responseData);
        $this->assertArrayHasKey('updated', $responseData);
        $this->assertEquals(2, $responseData['updated']);
    }

    public function testBulkDeactivateUsers(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user1 = $this->createTestUser('bulktest1@example.com');
        $user2 = $this->createTestUser('bulktest2@example.com');

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/deactivate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$user1->getId(), $user2->getId()]])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('updated', $responseData);
        $this->assertEquals(2, $responseData['updated']);

        // Verify users are deactivated
        $this->entityManager->clear();
        $updatedUser1 = $this->entityManager->getRepository(User::class)->find($user1->getId());
        $this->assertFalse($updatedUser1->isActive());
    }

    // ========== Bulk Role Assignment Tests ==========

    public function testBulkAssignRoles(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user1 = $this->createTestUser('bulktest3@example.com');
        $user2 = $this->createTestUser('bulktest4@example.com');

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/assign-roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userIds' => [$user1->getId(), $user2->getId()],
                'roles' => ['ROLE_ADMIN']
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals(2, $responseData['updated']);

        // Verify roles were added
        $this->entityManager->clear();
        $updatedUser1 = $this->entityManager->getRepository(User::class)->find($user1->getId());
        $this->assertContains('ROLE_ADMIN', $updatedUser1->getRoles());
    }

    // ========== Bulk Delete Tests ==========

    public function testBulkDeleteUsers(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user1 = $this->createTestUser('bulktest5@example.com');
        $user2 = $this->createTestUser('bulktest6@example.com');

        $user1Id = $user1->getId();
        $user2Id = $user2->getId();

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$user1Id, $user2Id]])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('deleted', $responseData);
        $this->assertEquals(2, $responseData['deleted']);

        // Verify users are deleted
        $this->entityManager->clear();
        $deletedUser1 = $this->entityManager->getRepository(User::class)->find($user1Id);
        $this->assertNull($deletedUser1);
    }

    public function testCannotBulkDeleteSelf(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user1 = $this->createTestUser('bulktest7@example.com');

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/delete',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$admin->getId(), $user1->getId()]])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        // Should only delete 1 user (not the admin themselves)
        $this->assertEquals(1, $responseData['deleted']);
        $this->assertArrayHasKey('skipped', $responseData);
    }

    // ========== Validation Tests ==========

    public function testBulkOperationRequiresUserIds(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/activate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testBulkAssignRolesRequiresRolesParameter(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('bulkadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user = $this->createTestUser('bulktest8@example.com');
        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/users/bulk/assign-roles',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['userIds' => [$user->getId()]])
        );

        $this->assertResponseStatusCodeSame(400);
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

    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
