<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Document;
use App\Entity\User;
use App\Message\ProcessDocumentMessage;
use App\MessageHandler\ProcessDocumentMessageHandler;
use App\Service\DocumentProcessingService;
use App\Service\ErrorCategorization\ErrorCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessDocumentMessageHandlerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DocumentProcessingService $processingService;
    private LoggerInterface $logger;
    private ErrorCategorizer $errorCategorizer;
    private ProcessDocumentMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->processingService = $this->createMock(DocumentProcessingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->errorCategorizer = new ErrorCategorizer();

        $this->handler = new ProcessDocumentMessageHandler(
            $this->entityManager,
            $this->processingService,
            $this->logger,
            $this->errorCategorizer
        );
    }

    public function testInvokeProcessesDocument(): void
    {
        // GIVEN a process document message
        $message = new ProcessDocumentMessage('doc-123');

        // AND a document exists
        $document = $this->createDocument();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('doc-123')
            ->willReturn($document);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($repository);

        // EXPECT processing service to be called
        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->with($document);

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN document should be processed (verified by mocks)
    }

    public function testInvokeLogsWhenDocumentNotFound(): void
    {
        // GIVEN a message for non-existent document
        $message = new ProcessDocumentMessage('non-existent');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('non-existent')
            ->willReturn(null);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($repository);

        // EXPECT error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Document not found'),
                $this->arrayHasKey('document_id')
            );

        // EXPECT processing service NOT to be called
        $this->processingService->expects($this->never())
            ->method('processDocument');

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN error should be logged (verified by mocks)
    }

    public function testInvokeHandlesProcessingException(): void
    {
        // GIVEN a document
        $message = new ProcessDocumentMessage('doc-123');
        $document = $this->createDocument();

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($document);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // AND processing throws exception
        $exception = new \RuntimeException('Processing failed');
        $this->processingService->expects($this->once())
            ->method('processDocument')
            ->with($document)
            ->willThrowException($exception);

        // EXPECT error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to process document'),
                $this->callback(function ($context) {
                    return isset($context['document_id'])
                        && isset($context['error']);
                })
            );

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN exception should be caught and logged (verified by mocks)
    }

    private function createDocument(): Document
    {
        $document = new Document();
        $document->setId('doc-123');
        $document->setFilename('test.pdf');
        $document->setOriginalName('test.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath('/test/path.pdf');

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }
}
