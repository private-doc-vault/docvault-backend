<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\User;
use App\Tests\EntityTestCase;
use App\Security\JwtTokenManager;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration tests for JWT authentication with Symfony Security
 *
 * Tests authentication flow, token validation, and security integration
 * following TDD methodology - RED phase
 */
class JwtAuthenticationTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private JwtTokenManager $jwtTokenManager;
    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function initializeServices(): void
    {
        if (isset($this->entityManager)) {
            return; // Already initialized
        }

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->jwtTokenManager = self::getContainer()->get(JwtTokenManager::class);

        // Create test user
        $this->createTestUser();
    }

    private function createTestUser(): void
    {
        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'test@example.com']);

        if ($existingUser) {
            $this->testUser = $existingUser;
            return;
        }

        $passwordHasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->testUser = $user;
    }

    public function testLoginWithValidCredentialsReturnsJwtToken(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $credentials = [
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
            json_encode($credentials)
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $responseData);
        $this->assertArrayHasKey('refresh_token', $responseData);
        $this->assertArrayHasKey('expires_in', $responseData);
        $this->assertIsString($responseData['token']);
        $this->assertIsString($responseData['refresh_token']);
        $this->assertIsInt($responseData['expires_in']);
    }

    public function testLoginWithInvalidCredentialsReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        
        $credentials = [
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
            json_encode($credentials)
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid credentials', $responseData['error']);
    }

    public function testLoginWithMissingCredentialsReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();
        
        $credentials = [
            'email' => 'test@example.com'
            // Missing password
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($credentials)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('Missing required field', $responseData['message']);
    }

    public function testAccessProtectedEndpointWithValidTokenReturnsSuccess(): void
    {
        // Arrange
        $client = static::createClient();
        $token = $this->getValidJwtToken();

        // Act
        $client->request(
            'GET',
            '/api/user/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function testAccessProtectedEndpointWithoutTokenReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'GET',
            '/api/user/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json']
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('JWT Token not found', $responseData['message']);
    }

    public function testAccessProtectedEndpointWithInvalidTokenReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $invalidToken = 'invalid.jwt.token';

        // Act
        $client->request(
            'GET',
            '/api/user/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $invalidToken,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Invalid JWT Token', $responseData['message']);
    }

    public function testAccessProtectedEndpointWithExpiredTokenReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $expiredToken = $this->getExpiredJwtToken();

        // Act
        $client->request(
            'GET',
            '/api/user/profile',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $expiredToken,
                'CONTENT_TYPE' => 'application/json'
            ]
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Expired JWT Token', $responseData['message']);
    }

    public function testRefreshTokenWithValidRefreshTokenReturnsNewToken(): void
    {
        // Arrange
        $client = static::createClient();
        $refreshToken = $this->getValidRefreshToken();

        // Act
        $client->request(
            'POST',
            '/api/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['refresh_token' => $refreshToken])
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $responseData);
        $this->assertArrayHasKey('refresh_token', $responseData);
        $this->assertIsString($responseData['token']);
        $this->assertIsString($responseData['refresh_token']);
    }

    public function testRefreshTokenWithInvalidRefreshTokenReturnsUnauthorized(): void
    {
        // Arrange
        $client = static::createClient();
        $invalidRefreshToken = 'invalid.refresh.token';

        // Act
        $client->request(
            'POST',
            '/api/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['refresh_token' => $invalidRefreshToken])
        );

        // Assert
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Invalid refresh token', $responseData['message']);
    }

    public function testLogoutWithValidTokenInvalidatesToken(): void
    {
        // Arrange
        $client = static::createClient();
        $token = $this->getValidJwtToken();

        // Act
        $client->request(
            'POST',
            '/api/auth/logout',
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
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Successfully logged out', $responseData['message']);
    }

    private function getValidJwtToken(): string
    {
        $this->initializeServices();
        return $this->jwtTokenManager->create($this->testUser);
    }

    private function getExpiredJwtToken(): string
    {
        $this->initializeServices();

        // Create a JWT token that expired 1 minute ago
        $payload = [
            'iat' => time() - 120, // Issued 2 minutes ago
            'exp' => time() - 60,  // Expired 1 minute ago
            'sub' => $this->testUser->getId(),
            'email' => $this->testUser->getEmail(),
            'roles' => $this->testUser->getRoles()
        ];

        // Get the JWT manager's encoder
        $jwtManager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');

        // Create an expired token manually using the encoder directly
        $encoder = self::getContainer()->get('lexik_jwt_authentication.encoder');
        $token = $encoder->encode($payload);

        return $token;
    }

    private function getValidRefreshToken(): string
    {
        $this->initializeServices();
        return $this->jwtTokenManager->createRefreshToken($this->testUser);
    }
}