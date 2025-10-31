<?php

namespace App\Tests\Unit\Service;

use App\Service\IdempotencyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests for IdempotencyService
 *
 * Idempotency tokens prevent duplicate processing of the same request
 */
class IdempotencyServiceTest extends TestCase
{
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private IdempotencyService $service;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new IdempotencyService(
            $this->cache,
            $this->logger,
            ttl: 3600 // 1 hour default
        );
    }

    public function testGeneratesUniqueIdempotencyToken(): void
    {
        // WHEN we generate two tokens
        $token1 = $this->service->generateToken();
        $token2 = $this->service->generateToken();

        // THEN they should be unique
        $this->assertNotEquals($token1, $token2);
        $this->assertNotEmpty($token1);
        $this->assertNotEmpty($token2);
    }

    public function testIdempotencyTokenIsValidFormat(): void
    {
        // WHEN we generate a token
        $token = $this->service->generateToken();

        // THEN it should be a valid UUID or hash format
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testCheckTokenReturnsFalseForNewToken(): void
    {
        // GIVEN a new token
        $token = 'test-token-123';

        // Mock cache to return null (token not found)
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(null);

        // WHEN we check if token was used
        $wasUsed = $this->service->wasTokenUsed($token);

        // THEN it should return false
        $this->assertFalse($wasUsed);
    }

    public function testCheckTokenReturnsTrueForUsedToken(): void
    {
        // GIVEN a used token
        $token = 'test-token-123';

        // Mock cache to return true (token exists)
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(true);

        // WHEN we check if token was used
        $wasUsed = $this->service->wasTokenUsed($token);

        // THEN it should return true
        $this->assertTrue($wasUsed);
    }

    public function testMarkTokenAsUsedStoresInCache(): void
    {
        // GIVEN a new token
        $token = 'test-token-123';

        // Mock cache set operation
        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())
            ->method('expiresAfter')
            ->with(3600)
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->with($this->stringContains($token))
            ->willReturnCallback(function ($key, $callback) use ($item) {
                return $callback($item);
            });

        // WHEN we mark token as used
        $this->service->markTokenAsUsed($token);

        // THEN cache should be called
        $this->assertTrue(true, 'Token should be stored in cache');
    }

    public function testProcessWithIdempotencyPreventsDoubleExecution(): void
    {
        // GIVEN a token that was already used
        $token = 'test-token-123';
        $executed = false;

        // Mock cache to return true (token exists)
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(true);

        // WHEN we try to process with same token
        $result = $this->service->processWithIdempotency($token, function () use (&$executed) {
            $executed = true;
            return 'result';
        });

        // THEN callback should NOT be executed
        $this->assertFalse($executed);
        $this->assertNull($result);
    }

    public function testProcessWithIdempotencyExecutesOnceForNewToken(): void
    {
        // GIVEN a new token
        $token = 'test-token-123';
        $executionCount = 0;

        // Mock cache operations
        $this->cache->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function ($key, $callback = null) use (&$executionCount) {
                if ($executionCount === 0) {
                    // First call - check if token used (returns null)
                    return null;
                } else {
                    // Second call - mark as used
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter')->willReturnSelf();
                    return $callback ? $callback($item) : true;
                }
            });

        // WHEN we process with new token
        $result = $this->service->processWithIdempotency($token, function () use (&$executionCount) {
            $executionCount++;
            return 'success';
        });

        // THEN callback should be executed exactly once
        $this->assertEquals(1, $executionCount);
        $this->assertEquals('success', $result);
    }

    public function testCustomTtlIsRespected(): void
    {
        // GIVEN a service with custom TTL
        $customService = new IdempotencyService(
            $this->cache,
            $this->logger,
            ttl: 7200 // 2 hours
        );

        $token = 'test-token-123';

        // Mock cache with TTL verification
        $item = $this->createMock(ItemInterface::class);
        $item->expects($this->once())
            ->method('expiresAfter')
            ->with(7200) // Should use custom TTL
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($item) {
                return $callback($item);
            });

        // WHEN we mark token as used
        $customService->markTokenAsUsed($token);

        // THEN custom TTL should be applied
        $this->assertTrue(true);
    }

    public function testLogsIdempotentRequests(): void
    {
        // GIVEN a used token
        $token = 'test-token-123';

        $this->cache->method('get')->willReturn(true);

        // EXPECT logger to be called
        $this->logger->expects($this->once())
            ->method('info');

        // WHEN we process with used token
        $result = $this->service->processWithIdempotency($token, function () {
            return 'result';
        });

        // THEN result should be null (not executed)
        $this->assertNull($result);
    }

    public function testGeneratesTokenFromContext(): void
    {
        // GIVEN some context data
        $context = [
            'document_id' => 'doc-123',
            'operation' => 'process',
            'timestamp' => 1234567890
        ];

        // WHEN we generate token from context
        $token1 = $this->service->generateTokenFromContext($context);
        $token2 = $this->service->generateTokenFromContext($context);

        // THEN same context should produce same token
        $this->assertEquals($token1, $token2);
        $this->assertNotEmpty($token1);
    }

    public function testDifferentContextProducesDifferentToken(): void
    {
        // GIVEN two different contexts
        $context1 = ['document_id' => 'doc-123'];
        $context2 = ['document_id' => 'doc-456'];

        // WHEN we generate tokens
        $token1 = $this->service->generateTokenFromContext($context1);
        $token2 = $this->service->generateTokenFromContext($context2);

        // THEN tokens should be different
        $this->assertNotEquals($token1, $token2);
    }
}
