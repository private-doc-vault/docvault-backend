<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\JwtTokenManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JWT token management
 * 
 * Tests JWT token creation, validation, and user extraction
 * following TDD methodology - RED phase
 */
class JwtTokenManagerTest extends TestCase
{
    public function testJwtTokenManagerCanBeInstantiated(): void
    {
        // Arrange
        $mockJwtManager = $this->createMock(\Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface::class);
        $mockEntityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        // Act
        $jwtTokenManager = new JwtTokenManager($mockJwtManager, $mockEntityManager);

        // Assert
        $this->assertInstanceOf(JwtTokenManager::class, $jwtTokenManager);
    }

    public function testCreateTokenForUserCallsLexikManager(): void
    {
        // Arrange
        $mockJwtManager = $this->createMock(\Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface::class);
        $mockEntityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $jwtTokenManager = new JwtTokenManager($mockJwtManager, $mockEntityManager);
        
        $user = new User();
        $user->setEmail('test@example.com');
        $expectedToken = 'test.jwt.token';

        $mockJwtManager
            ->expects($this->once())
            ->method('create')
            ->with($user)
            ->willReturn($expectedToken);

        // Act
        $result = $jwtTokenManager->createToken($user);

        // Assert
        $this->assertEquals($expectedToken, $result);
    }

    public function testParseTokenMethodExists(): void
    {
        // Arrange
        $mockJwtManager = $this->createMock(\Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface::class);
        $mockEntityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $jwtTokenManager = new JwtTokenManager($mockJwtManager, $mockEntityManager);

        // Act & Assert
        $this->assertTrue(method_exists($jwtTokenManager, 'parseToken'));
        $this->assertTrue(method_exists($jwtTokenManager, 'validateToken'));
        $this->assertTrue(method_exists($jwtTokenManager, 'getUsernameFromToken'));
        $this->assertTrue(method_exists($jwtTokenManager, 'getRolesFromToken'));
        $this->assertTrue(method_exists($jwtTokenManager, 'isTokenExpired'));
        $this->assertTrue(method_exists($jwtTokenManager, 'refreshToken'));
    }
}