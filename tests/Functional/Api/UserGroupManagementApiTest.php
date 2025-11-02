<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * User Group Management API Tests
 *
 * Tests cover:
 * - Creating user groups
 * - Listing user groups
 * - Updating group permissions
 * - Adding/removing users from groups
 * - Deleting groups
 * - Permission management
 */
class UserGroupManagementApiTest extends WebTestCase
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

            $testEmails = [
                'groupadmin@example.com',
                'groupuser@example.com'
            ];

            foreach ($testEmails as $email) {
                $user = $em->getRepository(User::class)
                    ->findOneBy(['email' => $email]);

                if ($user) {
                    $em->remove($user);
                }
            }

            $testGroupSlugs = [
                'test-editors',
                'test-viewers',
                'test-group-update',
                'system-group',
                'system-group-delete'
            ];

            foreach ($testGroupSlugs as $slug) {
                $group = $em->getRepository(UserGroup::class)
                    ->findOneBy(['slug' => $slug]);

                if ($group) {
                    // Remove all users from the group first
                    foreach ($group->getUsers()->toArray() as $user) {
                        $group->removeUser($user);
                    }
                    $em->remove($group);
                }
            }

            $em->flush();
            $em->clear();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    // ========== Create Group Tests ==========

    public function testCreateGroupRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('groupuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($user);

        $client->request(
            'POST',
            '/api/admin/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => 'Test Editors',
                'description' => 'Test editor group',
                'permissions' => ['document.write', 'document.read']
            ])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateGroupAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'name' => 'Test Editors',
                'description' => 'Group for document editors',
                'permissions' => ['document.write', 'document.read']
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $responseData);
        $this->assertEquals('Test Editors', $responseData['name']);
        $this->assertEquals('test-editors', $responseData['slug']);
        $this->assertContains('document.write', $responseData['permissions']);
        $this->assertContains('document.read', $responseData['permissions']);
    }

    public function testCreateGroupRequiresName(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/groups',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'description' => 'Missing name'
            ])
        );

        $this->assertResponseStatusCodeSame(400);
    }

    // ========== List Groups Tests ==========

    public function testListGroupsRequiresAdminRole(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('groupuser@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/admin/groups',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListGroupsAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        // Create a test group
        $group = $this->createTestGroup('Test Viewers', ['document.read'], $admin);

        $token = $this->generateJwtToken($admin);

        $client->request(
            'GET',
            '/api/admin/groups',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('groups', $responseData);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertGreaterThanOrEqual(1, $responseData['total']);
    }

    // ========== Get Group Details Tests ==========

    public function testGetGroupDetailsAsAdmin(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $group = $this->createTestGroup('Test Editors', ['document.write'], $admin);

        $token = $this->generateJwtToken($admin);

        $client->request(
            'GET',
            '/api/admin/groups/' . $group->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals($group->getId(), $responseData['id']);
        $this->assertEquals('Test Editors', $responseData['name']);
        $this->assertArrayHasKey('permissions', $responseData);
        $this->assertArrayHasKey('userCount', $responseData);
    }

    // ========== Update Group Tests ==========

    public function testUpdateGroupPermissions(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $group = $this->createTestGroup('Test Group Update', ['document.read'], $admin);

        $token = $this->generateJwtToken($admin);

        $client->request(
            'PUT',
            '/api/admin/groups/' . $group->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'permissions' => ['document.read', 'document.write', 'document.delete']
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(3, $responseData['permissions']);
        $this->assertContains('document.delete', $responseData['permissions']);
    }

    public function testCannotUpdateSystemGroup(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        // Create a system group
        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $group->setName('System Group');
        $group->setSlug('system-group');
        $group->setIsSystem(true);
        $group->setPermissions(['document.read']);
        $group->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        $token = $this->generateJwtToken($admin);

        $client->request(
            'PUT',
            '/api/admin/groups/' . $group->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'permissions' => ['document.write']
            ])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    // ========== Add/Remove Users Tests ==========

    public function testAddUserToGroup(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user = $this->createTestUser('groupuser@example.com', ['ROLE_USER']);
        $group = $this->createTestGroup('Test Editors', ['document.write'], $admin);

        $token = $this->generateJwtToken($admin);

        $client->request(
            'POST',
            '/api/admin/groups/' . $group->getId() . '/users',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userId' => $user->getId()
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('userCount', $responseData);
        $this->assertEquals(1, $responseData['userCount']);
    }

    public function testRemoveUserFromGroup(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $user = $this->createTestUser('groupuser@example.com', ['ROLE_USER']);
        $group = $this->createTestGroup('Test Editors', ['document.write'], $admin);

        // Add user to group first
        $group->addUser($user);
        $this->entityManager->flush();

        $token = $this->generateJwtToken($admin);

        $client->request(
            'DELETE',
            '/api/admin/groups/' . $group->getId() . '/users/' . $user->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals(0, $responseData['userCount']);
    }

    // ========== Delete Group Tests ==========

    public function testDeleteGroup(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $group = $this->createTestGroup('Test Editors', ['document.write'], $admin);

        $groupId = $group->getId();
        $token = $this->generateJwtToken($admin);

        $client->request(
            'DELETE',
            '/api/admin/groups/' . $groupId,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [200, 204]),
            'Delete should return 200 or 204'
        );

        // Verify group is deleted
        $deletedGroup = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        $this->assertNull($deletedGroup);
    }

    public function testCannotDeleteSystemGroup(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $admin = $this->createTestUser('groupadmin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);

        // Create a system group
        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $group->setName('System Group');
        $group->setSlug('system-group-delete');
        $group->setIsSystem(true);
        $group->setPermissions(['document.read']);
        $group->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        $token = $this->generateJwtToken($admin);

        $client->request(
            'DELETE',
            '/api/admin/groups/' . $group->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
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

    private function createTestGroup(string $name, array $permissions, User $createdBy): UserGroup
    {
        $group = new UserGroup();
        $group->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $group->setName($name);
        $group->setSlug(UserGroup::generateSlug($name));
        $group->setDescription('Test group: ' . $name);
        $group->setPermissions($permissions);
        $group->setCreatedBy($createdBy);
        $group->setCreatedAt(new \DateTimeImmutable());
        $group->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($group);
        $this->entityManager->flush();

        return $group;
    }

    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
