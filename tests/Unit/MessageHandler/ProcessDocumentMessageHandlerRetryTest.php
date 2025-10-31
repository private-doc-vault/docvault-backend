<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Document;
use App\Entity\User;
use App\Message\ProcessDocumentMessage;
use App\MessageHandler\ProcessDocumentMessageHandler;
use App\Service\DocumentProcessingService;
use App\Service\CircuitBreakerException;
use App\Service\ErrorCategorization\ErrorCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests for ProcessDocumentMessageHandler retry logic with different error types
 */
class ProcessDocumentMessageHandlerRetryTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentProcessingService $processingService;
    private LoggerInterface $logger;
    private ErrorCategorizer $errorCategorizer;
    private EntityRepository $repository;
    private ProcessDocumentMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->processingService = $this->createMock(DocumentProcessingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->errorCategorizer = new ErrorCategorizer();
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->willReturn($this->repository);

        $this->handler = new ProcessDocumentMessageHandler(
            $this->entityManager,
            $this->processingService,
            $this->logger,
            $this->errorCategorizer
        );
    }

    // Transient Error Tests (Should Retry)

    public function testHandlesTransportExceptionAsTransient(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws a TransportException (network error)
        $response = $this->createMock(ResponseInterface::class);
        $exception = new TransportException('Connection timeout');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->with($document)
            ->willThrowException($exception);

        // THEN error should be categorized as transient
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isTransient(), 'TransportException should be transient');

        // AND handler should rethrow for retry
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Connection timeout');

        // WHEN we invoke the handler
        ($this->handler)($message);
    }

    public function testHandlesServerExceptionAsTransient(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws a ServerException (5xx error)
        $exception = $this->createMock(ServerExceptionInterface::class);

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as transient
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isTransient(), 'ServerException should be transient');

        // AND handler should rethrow for retry
        $this->expectException(ServerExceptionInterface::class);

        // WHEN we invoke the handler
        ($this->handler)($message);
    }

    public function testHandlesCircuitBreakerExceptionAsTransient(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws CircuitBreakerException
        $exception = new CircuitBreakerException('Circuit breaker is open');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as transient
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isTransient(), 'CircuitBreakerException should be transient');

        // AND handler should rethrow for retry
        $this->expectException(CircuitBreakerException::class);

        // WHEN we invoke the handler
        ($this->handler)($message);
    }

    public function testHandlesTimeoutErrorAsTransient(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws timeout error
        $exception = new \RuntimeException('Request timeout occurred');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as transient
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isTransient(), 'Timeout errors should be transient');

        // AND handler should rethrow for retry
        $this->expectException(\RuntimeException::class);

        // WHEN we invoke the handler
        ($this->handler)($message);
    }

    public function testHandlesRateLimitErrorAsTransient(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws rate limit error
        $exception = new \RuntimeException('Rate limit exceeded - too many requests');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as transient
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isTransient(), 'Rate limit errors should be transient');

        // AND handler should rethrow for retry
        $this->expectException(\RuntimeException::class);

        // WHEN we invoke the handler
        ($this->handler)($message);
    }

    // Permanent Error Tests (Should Not Retry)

    public function testHandlesClientExceptionAsPermanent(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws a ClientException (4xx error)
        $exception = $this->createMock(ClientExceptionInterface::class);

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as permanent
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isPermanent(), 'ClientException should be permanent');

        // AND handler should handle it gracefully (not retry)
        ($this->handler)($message);
        $this->assertTrue(true, 'Handler should catch exception');
    }

    public function testHandlesFileNotFoundAsPermanent(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws file not found error
        $exception = new \RuntimeException('Document file not found');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as permanent
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isPermanent(), 'File not found should be permanent');

        // AND handler should handle it gracefully (not retry)
        ($this->handler)($message);
        $this->assertTrue(true, 'Handler should catch exception');
    }

    public function testHandlesInvalidInputAsPermanent(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws invalid input error
        $exception = new \InvalidArgumentException('Invalid document format');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as permanent
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isPermanent(), 'Invalid input should be permanent');

        // AND handler should handle it gracefully (not retry)
        ($this->handler)($message);
        $this->assertTrue(true, 'Handler should catch exception');
    }

    public function testHandlesAuthenticationFailureAsPermanent(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws authentication error
        $exception = new \RuntimeException('Authentication failed - invalid credentials');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN error should be categorized as permanent
        $category = $this->errorCategorizer->categorize($exception);
        $this->assertTrue($category->isPermanent(), 'Authentication failure should be permanent');

        // AND handler should handle it gracefully (not retry)
        ($this->handler)($message);
        $this->assertTrue(true, 'Handler should catch exception');
    }

    // Edge Cases

    public function testHandlesDocumentNotFound(): void
    {
        // GIVEN a message for non-existent document
        $message = new ProcessDocumentMessage('non-existent-id');

        $this->repository->method('find')->willReturn(null);

        // WHEN we invoke the handler
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Document not found'),
                $this->callback(function ($context) {
                    return $context['document_id'] === 'non-existent-id';
                })
            );

        ($this->handler)($message);

        // THEN processing service should not be called
        $this->processingService->expects($this->never())
            ->method('processDocument');

        $this->assertTrue(true, 'Handler should handle missing document gracefully');
    }

    public function testLogsErrorDetailsForDebugging(): void
    {
        // GIVEN a document to process
        $document = $this->createDocument();
        $message = new ProcessDocumentMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN processing throws an error
        $exception = new \RuntimeException('Test error with details');

        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->willThrowException($exception);

        // THEN handler should log comprehensive error details
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to process document'),
                $this->callback(function ($context) use ($document) {
                    return isset($context['document_id']) &&
                           $context['document_id'] === $document->getId() &&
                           isset($context['error']) &&
                           str_contains($context['error'], 'Test error') &&
                           isset($context['trace']);
                })
            );

        ($this->handler)($message);
    }

    private function createDocument(): Document
    {
        $document = new Document();
        $document->setId('test-doc-' . uniqid());
        $document->setFilename('test.pdf');
        $document->setOriginalName('test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/path.pdf');
        $document->setProcessingStatus(Document::STATUS_QUEUED);

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }
}
