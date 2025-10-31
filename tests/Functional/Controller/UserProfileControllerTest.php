<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Security\JwtTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for User Profile Management endpoints
 *
 * Tests user profile retrieval, updates, password changes, and account management
 * following TDD methodology with comprehensive coverage
 */
class UserProfileControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private JwtTokenManager $jwtTokenManager;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestUsers();
        }
        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
            $this->jwtTokenManager = self::getContainer()->get(JwtTokenManager::class);
            $this->cleanupTestUsers();
        }
    }

    private function cleanupTestUsers(): void
    {
        $testEmails = [
            'profile.user@example.com',
            'update.user@example.com',
            'password.user@example.com',
            'deactivate.user@example.com',
            'inactive.user@example.com'
        ];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);
            if ($user) {
                // Remove audit logs first due to foreign key constraint
                $auditLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->findBy(['user' => $user]);
                foreach ($auditLogs as $auditLog) {
                    $this->entityManager->remove($auditLog);
                }
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Test successful user profile retrieval
     *
     * @group profile
     */
    public function testGetUserProfileReturnsProfileDataForAuthenticatedUser(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser(
            'profile.user@example.com',
            'password123',
            'John',
            'Doe'
        );

        $token = $this->jwtTokenManager->create($testUser);

        // Act
        $client->request(
            'GET',
            '/api/profile',
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

        // Check response structure
        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('email', $responseData);
        $this->assertArrayHasKey('firstName', $responseData);
        $this->assertArrayHasKey('lastName', $responseData);
        $this->assertArrayHasKey('roles', $responseData);
        $this->assertArrayHasKey('isActive', $responseData);
        $this->assertArrayHasKey('isVerified', $responseData);
        $this->assertArrayHasKey('preferences', $responseData);
        $this->assertArrayHasKey('createdAt', $responseData);
        $this->assertArrayHasKey('updatedAt', $responseData);

        // Check response values
        $this->assertEquals($testUser->getId(), $responseData['id']);
        $this->assertEquals('profile.user@example.com', $responseData['email']);
        $this->assertEquals('John', $responseData['firstName']);
        $this->assertEquals('Doe', $responseData['lastName']);
        $this->assertEquals(['ROLE_USER'], $responseData['roles']);
        $this->assertTrue($responseData['isActive']);
        $this->assertTrue($responseData['isVerified']);

        // Ensure sensitive data is not returned
        $this->assertArrayNotHasKey('password', $responseData);
    }

    /**
     * Test profile retrieval without authentication
     *
     * @group profile
     */
    public function testGetUserProfileWithoutAuthenticationReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request('GET', '/api/profile');

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * Test profile retrieval with invalid token
     *
     * @group profile
     */
    public function testGetUserProfileWithInvalidTokenReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'GET',
            '/api/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer invalid-token-123',
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * Test successful profile update
     *
     * @group profile
     */
    public function testUpdateUserProfileUpdatesDataAndReturnsSuccess(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser(
            'update.user@example.com',
            'password123',
            'Jane',
            'Smith'
        );

        $token = $this->jwtTokenManager->create($testUser);

        $updateData = [
            'firstName' => 'Janet',
            'lastName' => 'Johnson',
            'preferences' => [
                'theme' => 'dark',
                'language' => 'en',
                'timezone' => 'UTC'
            ]
        ];

        // Act
        $client->request(
            'PUT',
            '/api/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($updateData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('successfully updated', $responseData['message']);

        // Verify data was actually updated in database
        $this->entityManager->refresh($testUser);
        $this->assertEquals('Janet', $testUser->getFirstName());
        $this->assertEquals('Johnson', $testUser->getLastName());

        $preferences = $testUser->getPreferences();
        $this->assertEquals('dark', $preferences['theme']);
        $this->assertEquals('en', $preferences['language']);
        $this->assertEquals('UTC', $preferences['timezone']);
    }

    /**
     * Test profile update with empty data
     *
     * @group profile
     */
    public function testUpdateUserProfileWithEmptyDataReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('update.user@example.com', 'password123');
        $token = $this->jwtTokenManager->create($testUser);

        // Act
        $client->request(
            'PUT',
            '/api/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('No data', $responseData['error']);
    }

    /**
     * Test profile update with invalid JSON
     *
     * @group profile
     */
    public function testUpdateUserProfileWithInvalidJsonReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('update.user@example.com', 'password123');
        $token = $this->jwtTokenManager->create($testUser);

        // Act
        $client->request(
            'PUT',
            '/api/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            '{"invalid": json}'
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid JSON data provided', $responseData['error']);
    }

    /**
     * Test successful password change
     *
     * @group profile
     */
    public function testChangePasswordWithValidDataUpdatesPasswordAndReturnsSuccess(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('password.user@example.com', 'oldPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $passwordData = [
            'currentPassword' => 'oldPassword123',
            'newPassword' => 'newSecurePassword456!',
            'confirmPassword' => 'newSecurePassword456!'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/change-password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($passwordData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('successfully changed', $responseData['message']);

        // Verify password was actually changed
        $passwordHasher = self::getContainer()->get('security.user_password_hasher');
        $this->entityManager->refresh($testUser);
        $this->assertTrue($passwordHasher->isPasswordValid($testUser, 'newSecurePassword456!'));
        $this->assertFalse($passwordHasher->isPasswordValid($testUser, 'oldPassword123'));
    }

    /**
     * Test password change with incorrect current password
     *
     * @group profile
     */
    public function testChangePasswordWithIncorrectCurrentPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('password.user@example.com', 'correctPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $passwordData = [
            'currentPassword' => 'wrongPassword123',
            'newPassword' => 'newSecurePassword456!',
            'confirmPassword' => 'newSecurePassword456!'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/change-password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($passwordData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Current password is incorrect', $responseData['error']);
    }

    /**
     * Test password change with mismatched confirmation
     *
     * @group profile
     */
    public function testChangePasswordWithMismatchedConfirmationReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('password.user@example.com', 'oldPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $passwordData = [
            'currentPassword' => 'oldPassword123',
            'newPassword' => 'newSecurePassword456!',
            'confirmPassword' => 'differentPassword789!'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/change-password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($passwordData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('passwords do not match', $responseData['error']);
    }

    /**
     * Test password change with weak new password
     *
     * @group profile
     */
    public function testChangePasswordWithWeakPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('password.user@example.com', 'oldPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $passwordData = [
            'currentPassword' => 'oldPassword123',
            'newPassword' => '123', // Too weak
            'confirmPassword' => '123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/change-password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($passwordData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('password', strtolower($responseData['error']));
    }

    /**
     * Test password change with missing fields
     *
     * @group profile
     */
    public function testChangePasswordWithMissingFieldsReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('password.user@example.com', 'oldPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $passwordData = [
            'newPassword' => 'newSecurePassword456!',
            // Missing currentPassword and confirmPassword
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/change-password',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($passwordData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('required', $responseData['error']);
    }

    /**
     * Test successful account deactivation
     *
     * @group profile
     */
    public function testDeactivateAccountSetsUserInactiveAndReturnsSuccess(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('deactivate.user@example.com', 'password123');
        $token = $this->jwtTokenManager->create($testUser);

        $deactivationData = [
            'password' => 'password123',
            'reason' => 'User requested account closure'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/deactivate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($deactivationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('deactivated', $responseData['message']);

        // Verify user is actually deactivated
        $this->entityManager->refresh($testUser);
        $this->assertFalse($testUser->isActive());
    }

    /**
     * Test account deactivation with incorrect password
     *
     * @group profile
     */
    public function testDeactivateAccountWithIncorrectPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('deactivate.user@example.com', 'correctPassword123');
        $token = $this->jwtTokenManager->create($testUser);

        $deactivationData = [
            'password' => 'wrongPassword123',
            'reason' => 'User requested account closure'
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/deactivate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($deactivationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Password is incorrect', $responseData['error']);

        // Verify user is still active
        $this->entityManager->refresh($testUser);
        $this->assertTrue($testUser->isActive());
    }

    /**
     * Test account deactivation with missing password
     *
     * @group profile
     */
    public function testDeactivateAccountWithMissingPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('deactivate.user@example.com', 'password123');
        $token = $this->jwtTokenManager->create($testUser);

        $deactivationData = [
            'reason' => 'User requested account closure'
            // Missing password
        ];

        // Act
        $client->request(
            'POST',
            '/api/profile/deactivate',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($deactivationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Password is required to deactivate account', $responseData['error']);
    }

    /**
     * Test accessing endpoints with deactivated account
     *
     * @group profile
     */
    public function testAccessingEndpointsWithDeactivatedAccountReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('inactive.user@example.com', 'password123');
        $testUser->setIsActive(false);
        $this->entityManager->flush();

        $token = $this->jwtTokenManager->create($testUser);

        // Act
        $client->request(
            'GET',
            '/api/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('deactivated', strtolower($responseData['error']));
    }

    /**
     * Helper method to create test user
     */
    private function createTestUser(
        string $email,
        string $password,
        string $firstName = 'Test',
        string $lastName = 'User'
    ): User {
        $passwordHasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}