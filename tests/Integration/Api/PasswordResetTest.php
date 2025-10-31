<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use App\Entity\PasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for password reset functionality
 *
 * Tests the complete password reset flow including token generation,
 * validation, and password updates following TDD methodology
 */
class PasswordResetTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            $this->cleanupTestData();
        }
        parent::tearDown();
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if ($this->entityManager === null) {
            $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
            $this->cleanupTestData();
        }
        return $this->entityManager;
    }

    private function cleanupTestData(): void
    {
        if ($this->entityManager === null) {
            return;
        }

        // Clean up test users and their tokens
        $testEmails = [
            'test@example.com',
            'nonexistent@example.com',
            'ratelimit@example.com'
        ];

        foreach ($testEmails as $email) {
            // Remove password reset tokens first (due to foreign key)
            $tokens = $this->entityManager->getRepository(PasswordResetToken::class)
                ->createQueryBuilder('t')
                ->join('t.user', 'u')
                ->where('u.email = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->getResult();

            foreach ($tokens as $token) {
                $this->entityManager->remove($token);
            }

            // Then remove users
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Test password reset request with valid email
     */
    public function testRequestPasswordResetWithValidEmail(): void
    {
        // Arrange
        $client = static::createClient();
        $this->getEntityManager(); // Initialize entity manager
        $user = $this->createTestUser('test@example.com');

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'test@example.com'])
        );

        // Assert
        $responseCode = $client->getResponse()->getStatusCode();
        if ($responseCode !== Response::HTTP_OK) {
            echo "Response Code: " . $responseCode . "\n";
            echo "Response Content: " . $client->getResponse()->getContent() . "\n";
        }
        $this->assertEquals(Response::HTTP_OK, $responseCode);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('reset link', $responseData['message']);

        // Verify token was created in database
        $em = $this->getEntityManager();
        $tokens = $em->getRepository(PasswordResetToken::class)
            ->findBy(['user' => $user]);
        $this->assertCount(1, $tokens);
        $this->assertFalse($tokens[0]->isExpired());
    }

    /**
     * Test password reset request with non-existent email
     */
    public function testRequestPasswordResetWithNonExistentEmail(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'nonexistent@example.com'])
        );

        // Assert - Should return success for security (don't reveal if email exists)
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('reset link', $responseData['message']);

        // Verify no token was created
        $em = $this->getEntityManager();
        $tokens = $em->getRepository(PasswordResetToken::class)->findAll();
        $this->assertCount(0, $tokens);
    }

    /**
     * Test password reset request with invalid email format
     */
    public function testRequestPasswordResetWithInvalidEmail(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'invalid-email'])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('valid email', $responseData['error']);
    }

    /**
     * Test password reset request with missing email
     */
    public function testRequestPasswordResetWithMissingEmail(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('required', strtolower($responseData['error']));
    }

    /**
     * Test multiple password reset requests (rate limiting)
     */
    public function testMultiplePasswordResetRequestsRateLimited(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');

        // Act - First request
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'test@example.com'])
        );
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Act - Second request immediately
        $client->request(
            'POST',
            '/api/auth/password-reset/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'test@example.com'])
        );

        // Assert - Should be rate limited
        $this->assertEquals(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('rate limit', strtolower($responseData['error']));
    }

    /**
     * Test password reset confirmation with valid token
     */
    public function testPasswordResetConfirmWithValidToken(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');
        $token = $this->createPasswordResetToken($user);

        $newPassword = 'NewSecurePassword123!';

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => $token->getToken(),
                'password' => $newPassword
            ])
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertStringContainsString('successfully reset', $responseData['message']);

        // Verify token is now used
        $em = $this->getEntityManager();
        $em->refresh($token);
        $this->assertTrue($token->isUsed());

        // Verify user can login with new password
        $this->assertTrue($this->canLoginWithPassword($user->getEmail(), $newPassword));
    }

    /**
     * Test password reset confirmation with expired token
     */
    public function testPasswordResetConfirmWithExpiredToken(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');
        $token = $this->createExpiredPasswordResetToken($user);

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => $token->getToken(),
                'password' => 'NewPassword123!'
            ])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('expired', strtolower($responseData['error']));
    }

    /**
     * Test password reset confirmation with invalid token
     */
    public function testPasswordResetConfirmWithInvalidToken(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => 'invalid-token-123',
                'password' => 'NewPassword123!'
            ])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('invalid', strtolower($responseData['error']));
    }

    /**
     * Test password reset confirmation with weak password
     */
    public function testPasswordResetConfirmWithWeakPassword(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');
        $token = $this->createPasswordResetToken($user);

        // Act
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => $token->getToken(),
                'password' => '123' // Weak password
            ])
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('password', strtolower($responseData['error']));
    }

    /**
     * Test password reset token validation endpoint
     */
    public function testPasswordResetTokenValidation(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');
        $token = $this->createPasswordResetToken($user);

        // Act
        $client->request(
            'GET',
            '/api/auth/password-reset/validate/' . $token->getToken()
        );

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('valid', $responseData);
        $this->assertTrue($responseData['valid']);
        $this->assertArrayHasKey('email', $responseData);
        $this->assertEquals('test@example.com', $responseData['email']);
    }

    /**
     * Test used token cannot be reused
     */
    public function testUsedTokenCannotBeReused(): void
    {
        // Arrange
        $client = static::createClient();
        $user = $this->createTestUser('test@example.com');
        $token = $this->createPasswordResetToken($user);

        // First use - should succeed
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => $token->getToken(),
                'password' => 'NewPassword123!'
            ])
        );
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Act - Try to use same token again
        $client->request(
            'POST',
            '/api/auth/password-reset/confirm',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'token' => $token->getToken(),
                'password' => 'AnotherPassword123!'
            ])
        );

        // Assert - Should be rejected
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('used', strtolower($responseData['error']));
    }

    /**
     * Helper methods for test setup
     */
    private function createTestUser(string $email): User
    {
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

        $passwordHasher = self::getContainer()->get('security.user_password_hasher');
        $hashedPassword = $passwordHasher->hashPassword($user, 'oldPassword123');
        $user->setPassword($hashedPassword);

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createPasswordResetToken(User $user): PasswordResetToken
    {
        $token = new PasswordResetToken();
        $token->setUser($user);
        $token->setToken(bin2hex(random_bytes(32)));
        $token->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $token->setCreatedAt(new \DateTimeImmutable());

        $em = $this->getEntityManager();
        $em->persist($token);
        $em->flush();

        return $token;
    }

    private function createExpiredPasswordResetToken(User $user): PasswordResetToken
    {
        $token = new PasswordResetToken();
        $token->setUser($user);
        $token->setToken(bin2hex(random_bytes(32)));
        $token->setExpiresAt(new \DateTimeImmutable('-1 hour')); // Expired
        $token->setCreatedAt(new \DateTimeImmutable('-2 hours'));

        $em = $this->getEntityManager();
        $em->persist($token);
        $em->flush();

        return $token;
    }

    private function canLoginWithPassword(string $email, string $password): bool
    {
        // Test password change by checking if the user password was updated
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if (!$user) {
            return false;
        }

        $passwordHasher = self::getContainer()->get('security.user_password_hasher');
        return $passwordHasher->isPasswordValid($user, $password);
    }
}