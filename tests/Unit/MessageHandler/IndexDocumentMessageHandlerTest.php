<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Document;
use App\Entity\User;
use App\Message\IndexDocumentMessage;
use App\MessageHandler\IndexDocumentMessageHandler;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexDocumentMessageHandlerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private SearchService $searchService;
    private LoggerInterface $logger;
    private IndexDocumentMessageHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->searchService = $this->createMock(SearchService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new IndexDocumentMessageHandler(
            $this->entityManager,
            $this->searchService,
            $this->logger
        );
    }

    public function testInvokeIndexesCompletedDocument(): void
    {
        // GIVEN an index document message
        $message = new IndexDocumentMessage('doc-123');

        // AND a completed document exists with OCR text
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setOcrText('Extracted OCR text from document');
        $document->setSearchableContent('Extracted OCR text from document test.pdf');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('doc-123')
            ->willReturn($document);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($repository);

        // EXPECT search service to index the document
        $this->searchService->expects($this->once())
            ->method('indexDocument')
            ->with($document);

        // EXPECT success to be logged
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Document indexed successfully'),
                $this->callback(function ($context) {
                    return isset($context['document_id']);
                })
            );

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN document should be indexed (verified by mocks)
    }

    public function testInvokeLogsWhenDocumentNotFound(): void
    {
        // GIVEN a message for non-existent document
        $message = new IndexDocumentMessage('non-existent');

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
                $this->stringContains('Document not found for indexing'),
                $this->arrayHasKey('document_id')
            );

        // EXPECT search service NOT to be called
        $this->searchService->expects($this->never())
            ->method('indexDocument');

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN error should be logged (verified by mocks)
    }

    public function testInvokeSkipsIndexingForNonCompletedDocuments(): void
    {
        // GIVEN a document that is not completed
        $message = new IndexDocumentMessage('doc-123');
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_QUEUED);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($document);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // EXPECT search service NOT to be called
        $this->searchService->expects($this->never())
            ->method('indexDocument');

        // EXPECT warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Skipping indexing for non-completed document'),
                $this->callback(function ($context) {
                    return isset($context['document_id'])
                        && isset($context['status']);
                })
            );

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN indexing should be skipped (verified by mocks)
    }

    public function testInvokeRethrowsExceptionForMessengerRetry(): void
    {
        // GIVEN a completed document
        $message = new IndexDocumentMessage('doc-123');
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setOcrText('Some text');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($document);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // AND indexing throws exception (transient error like network failure)
        $exception = new \RuntimeException('Meilisearch connection failed');
        $this->searchService->expects($this->once())
            ->method('indexDocument')
            ->with($document)
            ->willThrowException($exception);

        // EXPECT error to be logged
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to index document'),
                $this->callback(function ($context) {
                    return isset($context['document_id'])
                        && isset($context['error']);
                })
            );

        // EXPECT exception to be rethrown for Messenger to handle retry
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Meilisearch connection failed');

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN exception should be rethrown to trigger Messenger retry logic
    }

    public function testInvokeSkipsIndexingForDocumentsWithoutOcrText(): void
    {
        // GIVEN a completed document without OCR text
        $message = new IndexDocumentMessage('doc-123');
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setOcrText(null); // No OCR text

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($document);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // EXPECT search service NOT to be called
        $this->searchService->expects($this->never())
            ->method('indexDocument');

        // EXPECT warning to be logged
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Skipping indexing for document without OCR text'),
                $this->arrayHasKey('document_id')
            );

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN indexing should be skipped (verified by mocks)
    }

    public function testInvokeIndexesDocumentWithEmptyOcrText(): void
    {
        // GIVEN a completed document with empty (but not null) OCR text
        $message = new IndexDocumentMessage('doc-123');
        $document = $this->createDocument();
        $document->setProcessingStatus(Document::STATUS_COMPLETED);
        $document->setOcrText(''); // Empty string (e.g., blank page)

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($document);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // EXPECT search service to be called (empty text is valid, e.g., blank pages)
        $this->searchService->expects($this->once())
            ->method('indexDocument')
            ->with($document);

        $this->logger->expects($this->once())
            ->method('info');

        // WHEN handler is invoked
        ($this->handler)($message);

        // THEN document should be indexed even with empty text
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
        $document->setProcessingStatus(Document::STATUS_QUEUED);

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }
}
