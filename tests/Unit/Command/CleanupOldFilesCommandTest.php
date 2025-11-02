<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupOldFilesCommand;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupOldFilesCommandTest extends TestCase
{
    private DocumentRepository $documentRepository;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private string $basePath;
    private CleanupOldFilesCommand $command;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->basePath = sys_get_temp_dir() . '/test_cleanup';

        // Create test directory
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }

        $this->command = new CleanupOldFilesCommand(
            $this->documentRepository,
            $this->entityManager,
            $this->logger,
            $this->basePath,
            30 // default retention days
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->basePath)) {
            $this->removeDirectory($this->basePath);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $this->removeDirectory("$path/$file");
        }
        rmdir($path);
    }

    public function testCommandExecutesSuccessfully(): void
    {
        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $output = $commandTester->getDisplay();
        $this->assertTrue(
            str_contains($output, 'No files to clean up') || str_contains($output, 'Cleanup completed'),
            'Expected output to contain either "No files to clean up" or "Cleanup completed"'
        );
    }

    public function testCommandDeletesOldProcessedFiles(): void
    {
        // Create test file
        $testFile = $this->basePath . '/test_document.pdf';
        file_put_contents($testFile, 'test content');

        // Create mock document
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getFilePath')->willReturn($testFile);
        $document->method('getProcessingStatus')->willReturn(Document::STATUS_COMPLETED);
        $document->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-31 days'));

        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn([$document]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('Deleted file'));

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertFileDoesNotExist($testFile);
        $this->assertStringContainsString('1 file(s) deleted', $commandTester->getDisplay());
    }

    public function testCommandSkipsNonExistentFiles(): void
    {
        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getFilePath')->willReturn($this->basePath . '/non_existent.pdf');
        $document->method('getProcessingStatus')->willReturn(Document::STATUS_COMPLETED);
        $document->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-31 days'));

        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->willReturn([$document]);

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('File not found'));

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('0 file(s) deleted', $commandTester->getDisplay());
    }

    public function testCommandAcceptsCustomRetentionDays(): void
    {
        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->with($this->callback(function ($date) {
                // Check if date is approximately 60 days ago
                $expected = new \DateTimeImmutable('-60 days');
                $diff = abs($expected->getTimestamp() - $date->getTimestamp());
                return $diff < 2; // Allow 2 seconds tolerance
            }))
            ->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['--retention-days' => 60]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testCommandRunsInDryMode(): void
    {
        // Create test file
        $testFile = $this->basePath . '/test_document.pdf';
        file_put_contents($testFile, 'test content');

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getFilePath')->willReturn($testFile);
        $document->method('getProcessingStatus')->willReturn(Document::STATUS_COMPLETED);
        $document->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-31 days'));

        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->willReturn([$document]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute(['--dry-run' => true]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertFileExists($testFile); // File should still exist
        $this->assertStringContainsString('DRY RUN', $commandTester->getDisplay());
        $this->assertStringContainsString('Would delete', $commandTester->getDisplay());
    }

    public function testCommandHandlesFileDeleteErrors(): void
    {
        // Create a directory instead of file to trigger deletion error
        $testDir = $this->basePath . '/test_document.pdf';
        mkdir($testDir);
        file_put_contents($testDir . '/nested_file.txt', 'content');

        $document = $this->createMock(Document::class);
        $document->method('getId')->willReturn('doc-123');
        $document->method('getFilePath')->willReturn($testDir); // Directory path
        $document->method('getProcessingStatus')->willReturn(Document::STATUS_COMPLETED);
        $document->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-31 days'));

        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->willReturn([$document]);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to delete file'));

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('0 file(s) deleted', $commandTester->getDisplay());
        $this->assertStringContainsString('1 error(s)', $commandTester->getDisplay());
    }

    public function testCommandOnlyDeletesCompletedDocuments(): void
    {
        // This is ensured by the repository query
        // We just verify the repository method is called correctly
        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testCommandLogsProgress(): void
    {
        $documents = [];
        for ($i = 1; $i <= 3; $i++) {
            $testFile = $this->basePath . "/test_document_$i.pdf";
            file_put_contents($testFile, 'test content');

            $document = $this->createMock(Document::class);
            $document->method('getId')->willReturn("doc-$i");
            $document->method('getFilePath')->willReturn($testFile);
            $document->method('getProcessingStatus')->willReturn(Document::STATUS_COMPLETED);
            $document->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-31 days'));
            $documents[] = $document;
        }

        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->willReturn($documents);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Found 3 document(s)', $commandTester->getDisplay());
        $this->assertStringContainsString('3 file(s) deleted', $commandTester->getDisplay());
    }

    public function testCommandUsesDefaultRetentionDaysFromConstructor(): void
    {
        $this->documentRepository
            ->expects($this->once())
            ->method('findCompletedDocumentsOlderThan')
            ->with($this->callback(function ($date) {
                // Should use 30 days (from constructor)
                $expected = new \DateTimeImmutable('-30 days');
                $diff = abs($expected->getTimestamp() - $date->getTimestamp());
                return $diff < 2;
            }))
            ->willReturn([]);

        $commandTester = new CommandTester($this->command);
        $exitCode = $commandTester->execute([]); // No --retention-days option

        $this->assertEquals(Command::SUCCESS, $exitCode);
    }

    public function testCommandValidatesRetentionDaysOption(): void
    {
        $commandTester = new CommandTester($this->command);

        // Test invalid (negative) retention days
        $exitCode = $commandTester->execute(['--retention-days' => -1]);

        $this->assertEquals(Command::INVALID, $exitCode);
        $this->assertStringContainsString('must be a positive integer', $commandTester->getDisplay());
    }
}
