<?php

namespace App\Tests\Unit\Service;

use App\Service\CircuitBreaker;
use App\Service\CircuitBreakerException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $circuitBreaker;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->circuitBreaker = new CircuitBreaker(
            $this->logger,
            failureThreshold: 5,
            resetTimeout: 60
        );
    }

    public function testCircuitBreakerStartsInClosedState(): void
    {
        $this->assertTrue($this->circuitBreaker->isClosed());
        $this->assertFalse($this->circuitBreaker->isOpen());
        $this->assertFalse($this->circuitBreaker->isHalfOpen());
    }

    public function testCircuitBreakerAllowsCallsWhenClosed(): void
    {
        $called = false;
        $result = $this->circuitBreaker->call(function () use (&$called) {
            $called = true;
            return 'success';
        });

        $this->assertTrue($called);
        $this->assertEquals('success', $result);
    }

    public function testCircuitBreakerRecordsFailures(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            $this->circuitBreaker->call(function () {
                throw new \RuntimeException('Test failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
            throw $e;
        }
    }

    public function testCircuitBreakerOpensAfterThresholdFailures(): void
    {
        // Record 5 failures to reach threshold
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertTrue($this->circuitBreaker->isOpen());
        $this->assertFalse($this->circuitBreaker->isClosed());
    }

    public function testCircuitBreakerThrowsExceptionWhenOpen(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->expectException(CircuitBreakerException::class);
        $this->expectExceptionMessage('Circuit breaker is open');

        // This should throw CircuitBreakerException without calling the function
        $this->circuitBreaker->call(function () {
            $this->fail('Function should not be called when circuit is open');
        });
    }

    public function testCircuitBreakerTransitionsToHalfOpenAfterTimeout(): void
    {
        // Create circuit breaker with very short timeout for testing
        $circuitBreaker = new CircuitBreaker(
            $this->logger,
            failureThreshold: 3,
            resetTimeout: 1 // 1 second
        );

        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertTrue($circuitBreaker->isOpen());

        // Wait for reset timeout
        sleep(2);

        $this->assertTrue($circuitBreaker->isHalfOpen());
        $this->assertFalse($circuitBreaker->isOpen());
    }

    public function testCircuitBreakerClosesAfterSuccessInHalfOpenState(): void
    {
        // Create circuit breaker with very short timeout
        $circuitBreaker = new CircuitBreaker(
            $this->logger,
            failureThreshold: 3,
            resetTimeout: 1
        );

        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Wait for half-open state
        sleep(2);
        $this->assertTrue($circuitBreaker->isHalfOpen());

        // Successful call should close the circuit
        $result = $circuitBreaker->call(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertTrue($circuitBreaker->isClosed());
        $this->assertEquals(0, $circuitBreaker->getFailureCount());
    }

    public function testCircuitBreakerReopensAfterFailureInHalfOpenState(): void
    {
        // Create circuit breaker with very short timeout
        $circuitBreaker = new CircuitBreaker(
            $this->logger,
            failureThreshold: 3,
            resetTimeout: 1
        );

        // Open the circuit
        for ($i = 0; $i < 3; $i++) {
            try {
                $circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        // Wait for half-open state
        sleep(2);
        $this->assertTrue($circuitBreaker->isHalfOpen());

        // Failed call should reopen the circuit
        try {
            $circuitBreaker->call(function () {
                throw new \RuntimeException('Test failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertTrue($circuitBreaker->isOpen());
    }

    public function testCircuitBreakerResetsFailureCountOnSuccess(): void
    {
        // Record some failures
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertEquals(3, $this->circuitBreaker->getFailureCount());

        // Successful call should reset counter
        $this->circuitBreaker->call(function () {
            return 'success';
        });

        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testCircuitBreakerLogsStateTransitions(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Circuit breaker opened'),
                $this->isType('array')
            );

        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }
    }

    public function testCircuitBreakerCanBeManuallyReset(): void
    {
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->circuitBreaker->call(function () {
                    throw new \RuntimeException('Test failure');
                });
            } catch (\RuntimeException $e) {
                // Expected
            }
        }

        $this->assertTrue($this->circuitBreaker->isOpen());

        // Manual reset
        $this->circuitBreaker->reset();

        $this->assertTrue($this->circuitBreaker->isClosed());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    public function testCircuitBreakerPreservesReturnValue(): void
    {
        $result = $this->circuitBreaker->call(function () {
            return ['key' => 'value', 'number' => 42];
        });

        $this->assertEquals(['key' => 'value', 'number' => 42], $result);
    }

    public function testCircuitBreakerPreservesExceptionType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom exception message');

        $this->circuitBreaker->call(function () {
            throw new \InvalidArgumentException('Custom exception message');
        });
    }
}
