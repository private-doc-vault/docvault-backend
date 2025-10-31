<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for RoleController endpoints
 *
 * Tests role and permission management functionality
 */
class RoleControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

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

    private function initializeEntityManager(): void
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
            $this->cleanupTestData();
        }
    }

    private function cleanupTestData(): void
    {
        // Clean up test groups
        $testGroups = $this->entityManager->getRepository(UserGroup::class)
            ->createQueryBuilder('g')
            ->where('g.name LIKE :testName')
            ->setParameter('testName', '%Test Group%')
            ->getQuery()
            ->getResult();

        foreach ($testGroups as $group) {
            $this->entityManager->remove($group);
        }

        // Clean up test users
        $testUsers = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.email LIKE :testEmail')
            ->setParameter('testEmail', '%test_role%')
            ->getQuery()
            ->getResult();

        foreach ($testUsers as $user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testGetCurrentUserPermissions(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $user = $this->createTestUserWithPermissions(['document.read']);
        $token = $this->getJwtTokenForUser($user);

        // Act
        $client->request(
            'GET',
            '/api/roles/me',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayHasKey('permissions', $responseData['user']);
        $this->assertArrayHasKey('roles', $responseData['user']);
        $this->assertArrayHasKey('groups', $responseData['user']);
        $this->assertContains('document.read', $responseData['user']['permissions']);
    }

    public function testListAvailablePermissions(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $adminUser = $this->createTestUser(['ROLE_ADMIN']);
        $token = $this->getJwtTokenForUser($adminUser);

        // Act
        $client->request(
            'GET',
            '/api/roles/permissions',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('permissions', $responseData);
        $this->assertArrayHasKey('categories', $responseData);
        $this->assertArrayHasKey('document', $responseData['permissions']);
        $this->assertArrayHasKey('user', $responseData['permissions']);
        $this->assertArrayHasKey('admin', $responseData['permissions']);
    }

    public function testListGroupsAsAdmin(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $adminUser = $this->createTestUserWithPermissions(['user.manage'], ['ROLE_ADMIN']);
        $token = $this->getJwtTokenForUser($adminUser);

        // Act
        $client->request(
            'GET',
            '/api/roles/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('groups', $responseData);
        $this->assertArrayHasKey('total', $responseData);
    }

    public function testCreateGroupAsAdmin(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $adminUser = $this->createTestUserWithPermissions(['user.manage'], ['ROLE_ADMIN']);
        $token = $this->getJwtTokenForUser($adminUser);

        $groupData = [
            'name' => 'Test Group ' . uniqid(),
            'description' => 'Test group for functional testing',
            'permissions' => ['document.read', 'document.write'],
            'isActive' => true
        ];

        // Act
        $client->request(
            'POST',
            '/api/roles/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($groupData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('group', $responseData);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals($groupData['name'], $responseData['group']['name']);
        $this->assertEquals($groupData['description'], $responseData['group']['description']);
        $this->assertEquals($groupData['permissions'], $responseData['group']['permissions']);
    }

    public function testUserWithoutPermissionCannotAccessGroupManagement(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $regularUser = $this->createTestUser(['ROLE_USER']);
        $token = $this->getJwtTokenForUser($regularUser);

        // Act
        $client->request(
            'GET',
            '/api/roles/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    private function createTestUser(array $roles): User
    {
        $this->initializeEntityManager();
        $passwordHasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail('test_role_' . uniqid() . '@example.com');
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

    private function createTestUserWithPermissions(array $permissions, array $roles = ['ROLE_USER']): User
    {
        $user = $this->createTestUser($roles);

        if (!empty($permissions)) {
            $group = $this->createTestGroup($permissions);
            $user->getGroups()->add($group);
            $group->getUsers()->add($user);

            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        return $user;
    }

    private function createTestGroup(array $permissions): UserGroup
    {
        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $groupName = 'Test Group ' . uniqid();
        $group->setName($groupName);
        $group->setSlug(UserGroup::generateSlug($groupName));
        $group->setDescription('Test group for role testing');
        $group->setPermissions($permissions);
        $group->setCreatedAt(new \DateTimeImmutable());
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    private function getJwtTokenForUser(User $user): string
    {
        $jwtTokenManager = self::getContainer()->get(\App\Security\JwtTokenManager::class);
        return $jwtTokenManager->create($user);
    }
}