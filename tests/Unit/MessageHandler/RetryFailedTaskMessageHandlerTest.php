<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Document;
use App\Entity\User;
use App\Message\RetryFailedTaskMessage;
use App\MessageHandler\RetryFailedTaskMessageHandler;
use App\Service\DocumentProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetryFailedTaskMessageHandlerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentProcessingService $processingService;
    private LoggerInterface $logger;
    private EntityRepository $repository;
    private RetryFailedTaskMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->processingService = $this->createMock(DocumentProcessingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->repository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->willReturn($this->repository);

        $this->handler = new RetryFailedTaskMessageHandler(
            $this->entityManager,
            $this->processingService,
            $this->logger
        );
    }

    public function testRetriesFailedDocument(): void
    {
        // GIVEN a failed document
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_FAILED);
        $document->setProcessingError('OCR service timeout');

        $message = new RetryFailedTaskMessage(
            $document->getId(),
            'Manual retry after service recovery'
        );

        $this->repository->expects($this->once())
            ->method('find')
            ->with($document->getId())
            ->willReturn($document);

        // EXPECT retry to be triggered
        $this->processingService->expects($this->once())
            ->method('retryProcessing')
            ->with($document);

        // WHEN we handle the message
        ($this->handler)($message);

        // THEN processing should be retried
        $this->assertTrue(true);
    }

    public function testDoesNotRetryNonFailedDocument(): void
    {
        // GIVEN a document that is not failed
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);

        $message = new RetryFailedTaskMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // EXPECT no retry to be triggered
        $this->processingService->expects($this->never())
            ->method('retryProcessing');

        // EXPECT warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Cannot retry document'),
                $this->callback(function ($context) {
                    return $context['current_status'] === Document::STATUS_COMPLETED;
                })
            );

        // WHEN we handle the message
        ($this->handler)($message);
    }

    public function testHandlesDocumentNotFound(): void
    {
        // GIVEN a non-existent document ID
        $message = new RetryFailedTaskMessage('non-existent-id', 'Test reason');

        $this->repository->method('find')->willReturn(null);

        // EXPECT no retry
        $this->processingService->expects($this->never())
            ->method('retryProcessing');

        // EXPECT error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Document not found'),
                $this->callback(function ($context) {
                    return $context['document_id'] === 'non-existent-id';
                })
            );

        // WHEN we handle the message
        ($this->handler)($message);
    }

    public function testLogsRetryReason(): void
    {
        // GIVEN a failed document with retry reason
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_FAILED);

        $message = new RetryFailedTaskMessage(
            $document->getId(),
            'Service recovered, retrying all failed tasks'
        );

        $this->repository->method('find')->willReturn($document);

        // EXPECT reason to be logged (handler logs twice: start and success)
        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->logicalOr(
                    $this->stringContains('Manually retrying'),
                    $this->stringContains('Manual retry initiated')
                )
            );

        // WHEN we handle the message
        ($this->handler)($message);

        // THEN handler should succeed
        $this->assertTrue(true);
    }

    public function testRethrowsExceptionsForRetry(): void
    {
        // GIVEN a failed document
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_FAILED);

        $message = new RetryFailedTaskMessage($document->getId());

        $this->repository->method('find')->willReturn($document);

        // WHEN retry fails
        $exception = new \RuntimeException('Retry failed');
        $this->processingService->method('retryProcessing')
            ->willThrowException($exception);

        // THEN exception should be rethrown
        $this->expectException(\RuntimeException::class);

        ($this->handler)($message);
    }

    public function testMessageStoresDocumentId(): void
    {
        // WHEN we create a message
        $message = new RetryFailedTaskMessage('doc-123', 'Test reason');

        // THEN it should store the document ID
        $this->assertEquals('doc-123', $message->getDocumentId());
        $this->assertEquals('Test reason', $message->getReason());
    }

    public function testMessageAllowsNullReason(): void
    {
        // WHEN we create a message without reason
        $message = new RetryFailedTaskMessage('doc-123');

        // THEN reason should be null
        $this->assertEquals('doc-123', $message->getDocumentId());
        $this->assertNull($message->getReason());
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
