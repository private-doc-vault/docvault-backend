<?php

namespace App\Tests\Unit\Service;

use App\Service\ErrorCategorization\ErrorCategory;
use App\Service\ErrorCategorization\ErrorCategorizer;
use App\Service\CircuitBreakerException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ErrorCategorizerTest extends TestCase
{
    private ErrorCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new ErrorCategorizer();
    }

    // Transient Error Tests

    public function testCategorizesTransportExceptionAsTransient(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new TransportException('Connection timeout');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
        $this->assertTrue($category->isTransient());
        $this->assertTrue($this->categorizer->shouldRetry($exception));
    }

    public function testCategorizesServerExceptionAsTransient(): void
    {
        // Create a mock that implements ServerExceptionInterface
        $exception = $this->createMock(ServerExceptionInterface::class);

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
        $this->assertTrue($category->isTransient());
    }

    public function testCategorizesCircuitBreakerExceptionAsTransient(): void
    {
        $exception = new CircuitBreakerException('Circuit breaker is open');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
        $this->assertTrue($this->categorizer->shouldRetry($exception));
    }

    public function testCategorizesTimeoutErrorAsTransient(): void
    {
        $exception = new \RuntimeException('Request timeout occurred');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }

    public function testCategorizesConnectionRefusedAsTransient(): void
    {
        $exception = new \RuntimeException('Connection refused by server');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }

    public function testCategorizesRateLimitErrorAsTransient(): void
    {
        $exception = new \RuntimeException('Rate limit exceeded');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }

    public function testCategorizesServiceUnavailableAsTransient(): void
    {
        $exception = new \RuntimeException('Service temporarily unavailable');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }

    public function testCategorizesDeadlockAsTransient(): void
    {
        $exception = new \RuntimeException('Deadlock detected');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }

    // Permanent Error Tests

    public function testCategorizesClientExceptionAsPermanent(): void
    {
        // Create a mock that implements ClientExceptionInterface
        $exception = $this->createMock(ClientExceptionInterface::class);

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
        $this->assertTrue($category->isPermanent());
        $this->assertFalse($this->categorizer->shouldRetry($exception));
    }

    public function testCategorizesFileNotFoundAsPermanent(): void
    {
        $exception = new \RuntimeException('File not found');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesInvalidInputAsPermanent(): void
    {
        $exception = new \InvalidArgumentException('Invalid parameter provided');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesUnauthorizedAsPermanent(): void
    {
        $exception = new \RuntimeException('Unauthorized access');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesForbiddenAsPermanent(): void
    {
        $exception = new \RuntimeException('Forbidden: Access denied');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesAuthenticationFailedAsPermanent(): void
    {
        $exception = new \RuntimeException('Authentication failed');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesBadRequestAsPermanent(): void
    {
        $exception = new \RuntimeException('Bad request format');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    // Default Behavior Tests

    public function testCategorizesUnknownErrorAsPermanentByDefault(): void
    {
        $exception = new \RuntimeException('Some unknown error occurred');

        $category = $this->categorizer->categorize($exception);

        // Unknown errors default to permanent to prevent infinite retries
        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testCategorizesGenericExceptionAsPermanentByDefault(): void
    {
        $exception = new \Exception('Generic exception');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    // Description Tests

    public function testProvidesDescriptionForTransientError(): void
    {
        $exception = new \RuntimeException('Connection timeout');

        $description = $this->categorizer->getDescription($exception);

        $this->assertStringContainsString('Transient error', $description);
        $this->assertStringContainsString('Will retry', $description);
        $this->assertStringContainsString('RuntimeException', $description);
    }

    public function testProvidesDescriptionForPermanentError(): void
    {
        $exception = new \InvalidArgumentException('Invalid input');

        $description = $this->categorizer->getDescription($exception);

        $this->assertStringContainsString('Permanent error', $description);
        $this->assertStringContainsString('Will not retry', $description);
        $this->assertStringContainsString('InvalidArgumentException', $description);
    }

    // Edge Cases

    public function testIsCaseInsensitiveForErrorMessages(): void
    {
        $exception1 = new \RuntimeException('TIMEOUT occurred');
        $exception2 = new \RuntimeException('Timeout occurred');
        $exception3 = new \RuntimeException('timeout occurred');

        $this->assertSame(ErrorCategory::TRANSIENT, $this->categorizer->categorize($exception1));
        $this->assertSame(ErrorCategory::TRANSIENT, $this->categorizer->categorize($exception2));
        $this->assertSame(ErrorCategory::TRANSIENT, $this->categorizer->categorize($exception3));
    }

    public function testHandlesEmptyErrorMessage(): void
    {
        $exception = new \RuntimeException('');

        $category = $this->categorizer->categorize($exception);

        // Empty message defaults to permanent
        $this->assertSame(ErrorCategory::PERMANENT, $category);
    }

    public function testHandlesMultipleKeywordsInMessage(): void
    {
        // Transient keyword should take precedence if it appears first
        $exception = new \RuntimeException('Connection timeout with invalid response');

        $category = $this->categorizer->categorize($exception);

        $this->assertSame(ErrorCategory::TRANSIENT, $category);
    }
}
