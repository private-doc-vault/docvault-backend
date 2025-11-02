<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for AuthController endpoints
 *
 * Tests registration and login endpoints following TDD methodology
 * Covers all scenarios: valid requests, validation errors, edge cases
 */
class AuthControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

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

    private function initializeEntityManager(): void
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
            $this->cleanupTestUsers();
        }
    }

    private function cleanupTestUsers(): void
    {
        $testEmails = [
            'test@example.com',
            'newuser@example.com',
            'minimal@example.com',
            'jane.doe@example.com',
            'invalid.email.format',
            'existing@example.com',
            'inactive@example.com'
        ];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);
            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear(); // Clear the entity manager to remove cached entities
    }

    /**
     * Test user registration with valid data
     *
     * @group registration
     */
    public function testRegisterWithValidDataCreatesUserAndReturnsCreatedResponse(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();
        $this->initializeEntityManager();

        $registrationData = [
            'email' => 'newuser@example.com',
            'password' => 'securePassword123',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);

        // Check response structure
        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('email', $responseData);
        $this->assertArrayHasKey('firstName', $responseData);
        $this->assertArrayHasKey('lastName', $responseData);
        $this->assertArrayHasKey('isActive', $responseData);
        $this->assertArrayHasKey('isVerified', $responseData);
        $this->assertArrayHasKey('createdAt', $responseData);

        // Check response values
        $this->assertEquals('newuser@example.com', $responseData['email']);
        $this->assertEquals('John', $responseData['firstName']);
        $this->assertEquals('Doe', $responseData['lastName']);
        $this->assertTrue($responseData['isActive']);
        $this->assertFalse($responseData['isVerified']); // New users start unverified
        $this->assertIsString($responseData['id']); // UUID format
        $this->assertNotEmpty($responseData['createdAt']);

        // Verify user was actually created in database
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'newuser@example.com']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John', $user->getFirstName());
        $this->assertEquals('Doe', $user->getLastName());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isVerified());
    }

    /**
     * Test registration with minimal required data
     *
     * @group registration
     */
    public function testRegisterWithMinimalDataCreatesUser(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $registrationData = [
            'email' => 'minimal@example.com',
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('minimal@example.com', $responseData['email']);
        $this->assertNull($responseData['firstName']);
        $this->assertNull($responseData['lastName']);
    }

    /**
     * Test registration with missing email
     *
     * @group registration
     */
    public function testRegisterWithMissingEmailReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $registrationData = [
            'password' => 'password123',
            'firstName' => 'John'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('email', $responseData['violations']);
        $this->assertEquals('Email is required', $responseData['violations']['email']);
    }

    /**
     * Test registration with missing password
     *
     * @group registration
     */
    public function testRegisterWithMissingPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $registrationData = [
            'email' => 'test@example.com',
            'firstName' => 'John'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('password', $responseData['violations']);
        $this->assertEquals('Password is required', $responseData['violations']['password']);
    }

    /**
     * Test registration with invalid email format
     *
     * @group registration
     */
    public function testRegisterWithInvalidEmailFormatReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $registrationData = [
            'email' => 'invalid-email-format',
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid email format', $responseData['error']);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('email', $responseData['violations']);
        $this->assertEquals('Please provide a valid email address', $responseData['violations']['email']);
    }

    /**
     * Test registration with weak password
     *
     * @group registration
     */
    public function testRegisterWithWeakPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $registrationData = [
            'email' => 'test@example.com',
            'password' => '123'  // Too short
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Password too weak', $responseData['error']);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('password', $responseData['violations']);
        $this->assertEquals('Password must be at least 6 characters long', $responseData['violations']['password']);
    }

    /**
     * Test registration with existing email
     *
     * @group registration
     */
    public function testRegisterWithExistingEmailReturnsConflict(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        // Create existing user
        $this->createTestUser('existing@example.com', 'password123');

        $registrationData = [
            'email' => 'existing@example.com',
            'password' => 'newpassword123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($registrationData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('User with this email already exists', $responseData['error']);
    }

    /**
     * Test registration with empty JSON body
     *
     * @group registration
     */
    public function testRegisterWithEmptyJsonBodyReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            ''
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid JSON data provided', $responseData['error']);
    }

    /**
     * Test registration with invalid JSON
     *
     * @group registration
     */
    public function testRegisterWithInvalidJsonReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"invalid": json}'
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid JSON data provided', $responseData['error']);
    }

    /**
     * Test login with valid credentials
     *
     * @group login
     */
    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();
        $this->initializeEntityManager();
        $testUser = $this->createTestUser('test@example.com', 'password123');

        $loginData = [
            'email' => 'test@example.com',
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);

        // Check response structure
        $this->assertArrayHasKey('token', $responseData);
        $this->assertArrayHasKey('refresh_token', $responseData);
        $this->assertArrayHasKey('expires_in', $responseData);
        $this->assertArrayHasKey('user', $responseData);

        // Check token values
        $this->assertIsString($responseData['token']);
        $this->assertIsString($responseData['refresh_token']);
        $this->assertIsInt($responseData['expires_in']);
        $this->assertEquals(3600, $responseData['expires_in']); // 1 hour

        // Check user data
        $userData = $responseData['user'];
        $this->assertArrayHasKey('id', $userData);
        $this->assertArrayHasKey('email', $userData);
        $this->assertArrayHasKey('firstName', $userData);
        $this->assertArrayHasKey('lastName', $userData);
        $this->assertArrayHasKey('roles', $userData);
        $this->assertArrayHasKey('isActive', $userData);
        $this->assertArrayHasKey('isVerified', $userData);

        $this->assertEquals('test@example.com', $userData['email']);
        $this->assertEquals($testUser->getId(), $userData['id']);
        $this->assertEquals(['ROLE_USER'], $userData['roles']);
        $this->assertTrue($userData['isActive']);
    }

    /**
     * Test login with invalid credentials
     *
     * @group login
     */
    public function testLoginWithInvalidCredentialsReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();
        $this->createTestUser('test@example.com', 'password123');

        $loginData = [
            'email' => 'test@example.com',
            'password' => 'wrongpassword'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid credentials', $responseData['error']);
    }

    /**
     * Test login with non-existent user
     *
     * @group login
     */
    public function testLoginWithNonExistentUserReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $loginData = [
            'email' => 'nonexistent@example.com',
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid credentials', $responseData['error']);
    }

    /**
     * Test login with missing email
     *
     * @group login
     */
    public function testLoginWithMissingEmailReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $loginData = [
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('Missing required field', $responseData['message']);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('email', $responseData['violations']);
        $this->assertEquals('Email is required', $responseData['violations']['email']);
    }

    /**
     * Test login with missing password
     *
     * @group login
     */
    public function testLoginWithMissingPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        $loginData = [
            'email' => 'test@example.com'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('Missing required field', $responseData['message']);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertArrayHasKey('password', $responseData['violations']);
        $this->assertEquals('Password is required', $responseData['violations']['password']);
    }

    /**
     * Test login with inactive user
     *
     * @group login
     */
    public function testLoginWithInactiveUserReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();
        $testUser = $this->createTestUser('inactive@example.com', 'password123');
        $testUser->setIsActive(false);
        $this->entityManager->flush();

        $loginData = [
            'email' => 'inactive@example.com',
            'password' => 'password123'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($loginData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Account is deactivated', $responseData['error']);
    }

    /**
     * Test login with empty JSON body
     *
     * @group login
     */
    public function testLoginWithEmptyJsonBodyReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeEntityManager();

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            ''
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid JSON data provided', $responseData['error']);
    }

    /**
     * Helper method to create test user
     */
    private function createTestUser(string $email, string $password): User
    {
        $this->initializeEntityManager();
        $passwordHasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
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