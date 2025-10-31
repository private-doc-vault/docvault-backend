<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * User Management API Tests
 *
 * Tests cover:
 * - User listing (admin only)
 * - User details retrieval
 * - User update operations
 * - User deletion
 * - Permission checks
 * - Current user profile access
 */
class UserManagementApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestData();
        }

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
        $testEmails = [
            'usermgmt@example.com',
            'regularuser@example.com',
            'userupdate@example.com',
            'userdelete@example.com'
        ];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    // ========== List Users Tests ==========

    public function testListUsersRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(401, 'Listing users should require authentication');
    }

    public function testListUsersRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        // Regular user without admin role
        $testUser = $this->createTestUser('regularuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/admin/users',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403, 'Regular users should not list all users');
    }

    public function testListUsersAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'GET',
            '/api/admin/users',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('users', $responseData);
        $this->assertArrayHasKey('total', $responseData);
    }

    public function testListUsersReturnsPaginatedResults(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'GET',
            '/api/admin/users?page=1&limit=10',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('users', $responseData);
        $this->assertArrayHasKey('page', $responseData);
        $this->assertArrayHasKey('limit', $responseData);
        $this->assertArrayHasKey('total', $responseData);
    }

    // ========== Get User Details Tests ==========

    public function testGetUserDetailsRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/users/123e4567-e89b-12d3-a456-426614174000');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetUserDetailsRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('regularuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/admin/users/' . $testUser->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testGetUserDetailsAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $targetUser = $this->createTestUser('regularuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'GET',
            '/api/admin/users/' . $targetUser->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('email', $responseData);
        $this->assertArrayHasKey('firstName', $responseData);
        $this->assertArrayHasKey('lastName', $responseData);
        $this->assertArrayHasKey('roles', $responseData);
        $this->assertArrayHasKey('isActive', $responseData);

        $this->assertEquals($targetUser->getId(), $responseData['id']);
        $this->assertEquals($targetUser->getEmail(), $responseData['email']);
    }

    public function testGetNonExistentUserReturns404(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'GET',
            '/api/admin/users/00000000-0000-0000-0000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ========== Update User Tests ==========

    public function testUpdateUserRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('regularuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'PUT',
            '/api/admin/users/' . $testUser->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode(['firstName' => 'Updated'])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateUserAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $targetUser = $this->createTestUser('userupdate@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($adminUser);

        $newFirstName = 'UpdatedFirst';
        $newLastName = 'UpdatedLast';

        $client->request(
            'PUT',
            '/api/admin/users/' . $targetUser->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'firstName' => $newFirstName,
                'lastName' => $newLastName
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals($newFirstName, $responseData['firstName']);
        $this->assertEquals($newLastName, $responseData['lastName']);
    }

    public function testUpdateUserRoles(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $targetUser = $this->createTestUser('userupdate@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'PUT',
            '/api/admin/users/' . $targetUser->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'roles' => ['ROLE_USER', 'ROLE_ADMIN']
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertContains('ROLE_ADMIN', $responseData['roles']);
    }

    public function testDeactivateUser(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $targetUser = $this->createTestUser('userupdate@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'PUT',
            '/api/admin/users/' . $targetUser->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'isActive' => false
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertFalse($responseData['isActive']);
    }

    // ========== Delete User Tests ==========

    public function testDeleteUserRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('regularuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'DELETE',
            '/api/admin/users/00000000-0000-0000-0000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteUserAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $targetUser = $this->createTestUser('userdelete@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($adminUser);

        $userId = $targetUser->getId();

        $client->request(
            'DELETE',
            '/api/admin/users/' . $userId,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [200, 204]),
            'Delete should return 200 or 204'
        );

        // Verify user is deleted
        $deletedUser = $this->entityManager->getRepository(User::class)->find($userId);
        $this->assertNull($deletedUser, 'User should be deleted from database');
    }

    public function testDeleteNonExistentUserReturns404(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $adminUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($adminUser);

        $client->request(
            'DELETE',
            '/api/admin/users/00000000-0000-0000-0000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ========== Current User Profile Tests ==========

    public function testGetCurrentUserProfileRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/user/profile');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetCurrentUserProfile(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('usermgmt@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'GET',
            '/api/user/profile',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals($testUser->getId(), $responseData['id']);
        $this->assertEquals($testUser->getEmail(), $responseData['email']);
        $this->assertEquals($testUser->getFirstName(), $responseData['firstName']);
    }

    /**
     * Helper: Create a test user with specified roles
     */
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

    /**
     * Helper: Generate JWT token for authentication
     */
    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
