<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReindexDocumentsCommand;
use App\Entity\Document;
use App\Entity\User;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ReindexDocumentsCommandTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private SearchService $searchService;
    private ReindexDocumentsCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->searchService = $this->createMock(SearchService::class);

        $this->command = new ReindexDocumentsCommand(
            $this->entityManager,
            $this->searchService
        );

        // Set up command tester
        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteReindexesAllCompletedDocuments(): void
    {
        // GIVEN: Multiple completed documents exist
        $documents = [
            $this->createDocument('doc-1', Document::STATUS_COMPLETED),
            $this->createDocument('doc-2', Document::STATUS_COMPLETED),
            $this->createDocument('doc-3', Document::STATUS_COMPLETED),
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['processingStatus' => Document::STATUS_COMPLETED])
            ->willReturn($documents);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->with(Document::class)
            ->willReturn($repository);

        // EXPECT: Batch indexing to be called once with all documents
        $this->searchService->expects($this->once())
            ->method('indexMultipleDocumentsInChunks')
            ->with($documents, 100);

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Command should succeed
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Reindexing 3 documents', $output);
        $this->assertStringContainsString('Successfully reindexed 3 documents', $output);
    }

    public function testExecuteSkipsNonCompletedDocuments(): void
    {
        // GIVEN: Documents with various statuses
        $documents = [
            $this->createDocument('doc-1', Document::STATUS_COMPLETED),
            $this->createDocument('doc-2', Document::STATUS_COMPLETED),
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['processingStatus' => Document::STATUS_COMPLETED])
            ->willReturn($documents);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->willReturn($repository);

        // EXPECT: Batch indexing with only completed documents
        $this->searchService->expects($this->once())
            ->method('indexMultipleDocumentsInChunks')
            ->with($documents, 100);

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should process only completed documents
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Reindexing 2 documents', $output);
    }

    public function testExecuteHandlesIndexingErrors(): void
    {
        // GIVEN: Documents to index
        $documents = [
            $this->createDocument('doc-1', Document::STATUS_COMPLETED),
            $this->createDocument('doc-2', Document::STATUS_COMPLETED),
            $this->createDocument('doc-3', Document::STATUS_COMPLETED),
        ];

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($documents);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // AND: Batch indexing fails, triggering fallback to individual indexing
        $this->searchService->expects($this->once())
            ->method('indexMultipleDocumentsInChunks')
            ->willThrowException(new \RuntimeException('Batch indexing failed'));

        // AND: Second document fails during fallback individual indexing
        $this->searchService->expects($this->exactly(3))
            ->method('indexDocument')
            ->willReturnCallback(function ($doc) use ($documents) {
                if ($doc === $documents[1]) {
                    throw new \RuntimeException('Indexing failed');
                }
            });

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Command should continue and report errors
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully reindexed 2 documents', $output);
        $this->assertStringContainsString('Failed to reindex 1 documents', $output);
    }

    public function testExecuteHandlesNoDocuments(): void
    {
        // GIVEN: No completed documents
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('findBy')
            ->with(['processingStatus' => Document::STATUS_COMPLETED])
            ->willReturn([]);

        $this->entityManager->expects($this->once())
            ->method('getRepository')
            ->willReturn($repository);

        // EXPECT: No indexing calls
        $this->searchService->expects($this->never())
            ->method('indexDocument');

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should succeed with appropriate message
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No completed documents found', $output);
    }

    public function testExecuteWithBatchSizeOption(): void
    {
        // GIVEN: 10 documents
        $documents = [];
        for ($i = 1; $i <= 10; $i++) {
            $documents[] = $this->createDocument("doc-{$i}", Document::STATUS_COMPLETED);
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($documents);
        $this->entityManager->method('getRepository')->willReturn($repository);

        // EXPECT: Batch indexing with custom batch size
        $this->searchService->expects($this->once())
            ->method('indexMultipleDocumentsInChunks')
            ->with($documents, 5);

        // WHEN: Command is executed with batch size
        $this->commandTester->execute(['--batch-size' => 5]);

        // THEN: Should process in batches
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully reindexed 10 documents', $output);
    }

    public function testExecuteShowsProgressBar(): void
    {
        // GIVEN: Multiple documents
        $documents = [];
        for ($i = 1; $i <= 5; $i++) {
            $documents[] = $this->createDocument("doc-{$i}", Document::STATUS_COMPLETED);
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($documents);
        $this->entityManager->method('getRepository')->willReturn($repository);

        $this->searchService->method('indexMultipleDocumentsInChunks');

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should show progress
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Reindexing 5 documents', $output);
    }

    private function createDocument(string $id, string $status): Document
    {
        $document = new Document();
        $document->setId($id);
        $document->setFilename("test-{$id}.pdf");
        $document->setOriginalName("test-{$id}.pdf");
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setFilePath("/test/path-{$id}.pdf");
        $document->setProcessingStatus($status);
        $document->setOcrText('Some OCR text');

        $user = new User();
        $user->setEmail('test@example.com');
        $document->setUploadedBy($user);

        return $document;
    }
}
