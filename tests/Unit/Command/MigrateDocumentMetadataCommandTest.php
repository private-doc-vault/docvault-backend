<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\MigrateDocumentMetadataCommand;
use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateDocumentMetadataCommandTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private EntityRepository $documentRepository;
    private MigrateDocumentMetadataCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->documentRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Document::class)
            ->willReturn($this->documentRepository);

        $this->command = new MigrateDocumentMetadataCommand($this->entityManager);

        $application = new Application();
        $application->add($this->command);

        $command = $application->find('app:migrate-document-metadata');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteWithNoDocumentsToMigrate(): void
    {
        // GIVEN no documents need migration (all flat)
        $document = $this->createDocumentWithFlatMetadata();

        $this->documentRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([$document]);

        // WHEN we run the command
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No documents need migration', $output);
        $this->assertStringContainsString('Already flat', $output);
    }

    public function testExecuteWithDryRunShowsTransformations(): void
    {
        // GIVEN documents with nested metadata
        $document = $this->createDocumentWithNestedMetadata();

        $this->documentRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([$document]);

        // EXPECT no database updates in dry-run
        $this->entityManager->expects($this->never())
            ->method('flush');

        // WHEN we run with --dry-run
        $exitCode = $this->commandTester->execute(['--dry-run' => true]);

        // THEN command succeeds and shows what would change
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('Before:', $output);
        $this->assertStringContainsString('After:', $output);
        $this->assertStringContainsString('extracted_metadata', $output);
    }

    public function testExecuteMigratesNestedMetadata(): void
    {
        // GIVEN a document with nested metadata
        $document = $this->createDocumentWithNestedMetadata();

        // Called twice: once for migration, once for verification
        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([$document]);

        // EXPECT metadata to be updated
        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        // WHEN we run the command (with auto-confirm)
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN metadata should be flattened
        $this->assertEquals(0, $exitCode);
        $metadata = $document->getMetadata();
        $this->assertArrayNotHasKey('extracted_metadata', $metadata);
        $this->assertArrayHasKey('dates', $metadata);
        $this->assertArrayHasKey('amounts', $metadata);
        $this->assertEquals(['2024-01-15'], $metadata['dates']);
    }

    public function testExecutePreservesTopLevelFields(): void
    {
        // GIVEN a document with both nested and top-level fields
        $document = $this->createDocument();
        $document->setMetadata([
            'ocr_task_id' => 'task-123',
            'ocr_status' => Document::STATUS_COMPLETED,
            'extracted_metadata' => [
                'dates' => ['2024-01-15'],
                'amounts' => [1234.56]
            ]
        ]);

        // Called twice: once for migration, once for verification
        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([$document]);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        // WHEN we migrate
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN top-level fields should be preserved
        $this->assertEquals(0, $exitCode);
        $metadata = $document->getMetadata();
        $this->assertArrayHasKey('ocr_task_id', $metadata);
        $this->assertEquals('task-123', $metadata['ocr_task_id']);
        $this->assertArrayHasKey('ocr_status', $metadata);
        $this->assertArrayHasKey('dates', $metadata);
        $this->assertArrayNotHasKey('extracted_metadata', $metadata);
    }

    public function testExecuteHandlesEmptyMetadata(): void
    {
        // GIVEN a document with no metadata
        $document = $this->createDocument();
        $document->setMetadata(null);

        $this->documentRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([$document]);

        // WHEN we run the command
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds without errors
        $this->assertEquals(0, $exitCode);
        $this->assertNull($document->getMetadata());
    }

    public function testExecuteProcessesMultipleDocuments(): void
    {
        // GIVEN multiple documents with nested metadata
        $doc1 = $this->createDocumentWithNestedMetadata();
        $doc2 = $this->createDocumentWithNestedMetadata();
        $doc3 = $this->createDocumentWithFlatMetadata();

        // Called twice: once for migration, once for verification
        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([$doc1, $doc2, $doc3]);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        // WHEN we migrate
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN only nested documents should be migrated
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully migrated 2 documents', $output);
    }

    public function testExecuteUsesBatchSize(): void
    {
        // GIVEN multiple documents
        $documents = [];
        for ($i = 0; $i < 150; $i++) {
            $documents[] = $this->createDocumentWithNestedMetadata();
        }

        // Called twice: once for migration, once for verification
        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn($documents);

        // EXPECT flush to be called multiple times (batching)
        $this->entityManager->expects($this->atLeast(2))
            ->method('flush');

        // EXPECT clear to be called for memory management
        $this->entityManager->expects($this->atLeastOnce())
            ->method('clear');

        // WHEN we run with custom batch size
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute(['--batch-size' => 50]);

        // THEN command succeeds
        $this->assertEquals(0, $exitCode);
    }

    public function testExecuteCancelsOnUserDecline(): void
    {
        // GIVEN documents to migrate
        $document = $this->createDocumentWithNestedMetadata();

        $this->documentRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([$document]);

        // EXPECT no flush when user declines
        $this->entityManager->expects($this->never())
            ->method('flush');

        // WHEN user answers 'no' to confirmation
        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command exits successfully without changes
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('cancelled', $output);
    }

    public function testExecuteDisplaysVerificationStats(): void
    {
        // GIVEN documents to migrate
        $document = $this->createDocumentWithNestedMetadata();

        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([$document]);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        // WHEN we migrate
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN verification stats should be displayed
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Verification', $output);
        $this->assertStringContainsString('Flat structure', $output);
    }

    public function testCommandHasCorrectName(): void
    {
        $this->assertEquals('app:migrate-document-metadata', $this->command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $description = $this->command->getDescription();
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('metadata', strtolower($description));
    }

    public function testFlattenMetadataMovesFieldsToTopLevel(): void
    {
        // GIVEN a document with nested structure
        $document = $this->createDocument();
        $nestedMetadata = [
            'ocr_task_id' => 'task-123',
            'extracted_metadata' => [
                'dates' => ['2024-01-15'],
                'amounts' => [1234.56],
                'invoice_numbers' => ['INV-001']
            ]
        ];

        $document->setMetadata($nestedMetadata);

        // WHEN we apply flattenMetadata transformation
        // Called twice: once for migration, once for verification
        $this->documentRepository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturn([$document]);

        $this->entityManager->expects($this->atLeastOnce())
            ->method('flush');

        $this->commandTester->setInputs(['yes']);
        $this->commandTester->execute([]);

        // THEN all fields should be at top level
        $flatMetadata = $document->getMetadata();
        $this->assertArrayNotHasKey('extracted_metadata', $flatMetadata);
        $this->assertArrayHasKey('dates', $flatMetadata);
        $this->assertArrayHasKey('amounts', $flatMetadata);
        $this->assertArrayHasKey('invoice_numbers', $flatMetadata);
        $this->assertEquals(['2024-01-15'], $flatMetadata['dates']);
        $this->assertEquals([1234.56], $flatMetadata['amounts']);
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

    private function createDocumentWithNestedMetadata(): Document
    {
        $document = $this->createDocument();
        $document->setMetadata([
            'ocr_task_id' => 'task-' . uniqid(),
            'extracted_metadata' => [
                'dates' => ['2024-01-15'],
                'amounts' => [1234.56],
                'invoice_numbers' => ['INV-001']
            ]
        ]);

        return $document;
    }

    private function createDocumentWithFlatMetadata(): Document
    {
        $document = $this->createDocument();
        $document->setMetadata([
            'ocr_task_id' => 'task-' . uniqid(),
            'dates' => ['2024-01-15'],
            'amounts' => [1234.56],
            'invoice_numbers' => ['INV-001']
        ]);

        return $document;
    }
}
