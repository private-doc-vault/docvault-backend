<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\PasswordResetToken;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Unit tests for PasswordResetService
 *
 * Tests password reset token generation, validation, and management
 * following TDD methodology - RED phase
 */
class PasswordResetServiceTest extends TestCase
{
    private PasswordResetService $passwordResetService;
    private EntityManagerInterface $mockEntityManager;
    private EntityRepository $mockTokenRepository;
    private EntityRepository $mockUserRepository;
    private UserPasswordHasherInterface $mockPasswordHasher;
    private ValidatorInterface $mockValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockEntityManager = $this->createMock(EntityManagerInterface::class);
        $this->mockTokenRepository = $this->createMock(EntityRepository::class);
        $this->mockUserRepository = $this->createMock(EntityRepository::class);
        $this->mockPasswordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->mockValidator = $this->createMock(ValidatorInterface::class);

        $this->mockEntityManager
            ->method('getRepository')
            ->willReturnMap([
                [PasswordResetToken::class, $this->mockTokenRepository],
                [User::class, $this->mockUserRepository]
            ]);

        $this->passwordResetService = new PasswordResetService(
            $this->mockEntityManager,
            $this->mockPasswordHasher,
            $this->mockValidator
        );
    }

    public function testPasswordResetServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(PasswordResetService::class, $this->passwordResetService);
    }

    /**
     * Test token generation for existing user
     */
    public function testGenerateResetTokenForExistingUser(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');

        $this->mockUserRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'test@example.com'])
            ->willReturn($user);

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user, 'isUsed' => false])
            ->willReturn([]);

        $this->mockEntityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(PasswordResetToken::class));

        $this->mockEntityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $result = $this->passwordResetService->generateResetToken('test@example.com');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('token', $result);
        $this->assertIsString($result['token']);
        $this->assertEquals(64, strlen($result['token'])); // 32 bytes = 64 hex chars
    }

    /**
     * Test token generation for non-existent user
     */
    public function testGenerateResetTokenForNonExistentUser(): void
    {
        // Arrange
        $this->mockUserRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'nonexistent@example.com'])
            ->willReturn(null);

        $this->mockEntityManager
            ->expects($this->never())
            ->method('persist');

        // Act
        $result = $this->passwordResetService->generateResetToken('nonexistent@example.com');

        // Assert
        $this->assertTrue($result['success']); // Always return success for security
        $this->assertArrayNotHasKey('token', $result);
    }

    /**
     * Test rate limiting prevents multiple tokens
     */
    public function testGenerateResetTokenRateLimiting(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');
        $existingToken = $this->createMockPasswordResetToken($user);

        $this->mockUserRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['email' => 'test@example.com'])
            ->willReturn($user);

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findBy')
            ->with(['user' => $user, 'isUsed' => false])
            ->willReturn([$existingToken]);

        $this->mockEntityManager
            ->expects($this->never())
            ->method('persist');

        // Act
        $result = $this->passwordResetService->generateResetToken('test@example.com');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('rate limit', strtolower($result['error']));
    }

    /**
     * Test token validation with valid token
     */
    public function testValidateTokenWithValidToken(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');
        $token = $this->createMockPasswordResetToken($user);

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'valid-token-123'])
            ->willReturn($token);

        // Act
        $result = $this->passwordResetService->validateToken('valid-token-123');

        // Assert
        $this->assertTrue($result['valid']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals($user, $result['user']);
    }

    /**
     * Test token validation with invalid token
     */
    public function testValidateTokenWithInvalidToken(): void
    {
        // Arrange
        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'invalid-token'])
            ->willReturn(null);

        // Act
        $result = $this->passwordResetService->validateToken('invalid-token');

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test token validation with expired token
     */
    public function testValidateTokenWithExpiredToken(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');
        $expiredToken = $this->createMockPasswordResetToken($user, true);

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'expired-token'])
            ->willReturn($expiredToken);

        // Act
        $result = $this->passwordResetService->validateToken('expired-token');

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('expired', strtolower($result['error']));
    }

    /**
     * Test token validation with used token
     */
    public function testValidateTokenWithUsedToken(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');
        $usedToken = $this->createMockPasswordResetToken($user, false, true);

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'used-token'])
            ->willReturn($usedToken);

        // Act
        $result = $this->passwordResetService->validateToken('used-token');

        // Assert
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('used', strtolower($result['error']));
    }

    /**
     * Test password reset with valid token
     */
    public function testResetPasswordWithValidToken(): void
    {
        // Arrange
        $user = $this->createMockUser('test@example.com');
        $token = $this->createMockPasswordResetToken($user);
        $newPassword = 'NewSecurePassword123!';

        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'valid-token'])
            ->willReturn($token);

        $this->mockPasswordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->with($user, $newPassword)
            ->willReturn('hashed-new-password');

        $user->expects($this->once())
            ->method('setPassword')
            ->with('hashed-new-password');

        $token->expects($this->once())
            ->method('markAsUsed')
            ->with(null, null);

        $this->mockEntityManager
            ->expects($this->exactly(2))
            ->method('persist')
            ->with($this->logicalOr($user, $token));

        $this->mockEntityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $result = $this->passwordResetService->resetPassword('valid-token', $newPassword);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('reset', strtolower($result['message']));
    }

    /**
     * Test password reset with invalid token
     */
    public function testResetPasswordWithInvalidToken(): void
    {
        // Arrange
        $this->mockTokenRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['token' => 'invalid-token'])
            ->willReturn(null);

        $this->mockPasswordHasher
            ->expects($this->never())
            ->method('hashPassword');

        $this->mockEntityManager
            ->expects($this->never())
            ->method('persist');

        // Act
        $result = $this->passwordResetService->resetPassword('invalid-token', 'NewPassword123!');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test cleanup of expired tokens
     */
    public function testCleanupExpiredTokens(): void
    {
        // Arrange
        $expiredToken1 = $this->createMockPasswordResetToken(null, true);
        $expiredToken2 = $this->createMockPasswordResetToken(null, true);

        $this->mockEntityManager
            ->expects($this->once())
            ->method('createQuery')
            ->willReturn($this->createMockQuery([$expiredToken1, $expiredToken2]));

        $this->mockEntityManager
            ->expects($this->exactly(2))
            ->method('remove')
            ->with($this->logicalOr($expiredToken1, $expiredToken2));

        $this->mockEntityManager
            ->expects($this->once())
            ->method('flush');

        // Act
        $result = $this->passwordResetService->cleanupExpiredTokens();

        // Assert
        $this->assertEquals(2, $result);
    }

    /**
     * Helper methods for creating mock objects
     */
    private function createMockUser(string $email): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function createMockPasswordResetToken(?User $user = null, bool $expired = false, bool $used = false): PasswordResetToken
    {
        $token = $this->createMock(PasswordResetToken::class);

        if ($user) {
            $token->method('getUser')->willReturn($user);
        }

        $token->method('isExpired')->willReturn($expired);
        $token->method('isUsed')->willReturn($used);
        $token->method('getToken')->willReturn('mock-token-' . uniqid());

        // Add getCreatedAt() method to return a recent datetime for rate limiting tests
        $createdAt = new \DateTimeImmutable('-5 minutes'); // Recent enough to trigger rate limiting
        $token->method('getCreatedAt')->willReturn($createdAt);

        return $token;
    }

    private function createMockQuery(array $result): object
    {
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn($result);

        return $query;
    }
}