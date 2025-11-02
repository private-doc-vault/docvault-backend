<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\MigrateDocumentStatusCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class MigrateDocumentStatusCommandTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private MigrateDocumentStatusCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->entityManager->method('getConnection')
            ->willReturn($this->connection);

        $this->command = new MigrateDocumentStatusCommand($this->entityManager);

        $application = new Application();
        $application->add($this->command);

        $command = $application->find('app:migrate-document-status');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteWithNoDocumentsToMigrate(): void
    {
        // GIVEN no documents with old statuses
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 0); // uploaded: 0, pending: 0

        // WHEN we run the command
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No documents need migration', $output);
    }

    public function testExecuteWithDryRunShowsChanges(): void
    {
        // GIVEN 5 uploaded and 3 pending documents
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(5, 3); // uploaded: 5, pending: 3

        // EXPECT no database updates in dry-run mode
        $this->connection->expects($this->never())
            ->method('executeStatement');

        // WHEN we run with --dry-run
        $exitCode = $this->commandTester->execute(['--dry-run' => true]);

        // THEN command succeeds and shows what would change
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN MODE', $output);
        $this->assertStringContainsString('uploaded', $output);
        $this->assertStringContainsString('pending', $output);
        $this->assertStringContainsString('queued', $output);
        $this->assertStringContainsString('5', $output);
        $this->assertStringContainsString('3', $output);
    }

    public function testExecuteMigratesUploadedStatus(): void
    {
        // GIVEN 10 documents with 'uploaded' status
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 0); // uploaded: 10, pending: 0

        // EXPECT transaction to be used
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('commit');

        // EXPECT status update for 'uploaded' → 'queued'
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE documents SET processing_status'),
                $this->callback(function ($params) {
                    return $params['new_status'] === 'queued'
                        && $params['old_status'] === 'uploaded';
                })
            )
            ->willReturn(10);

        // Mock status distribution query
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['processing_status' => 'queued', 'count' => 10],
                ['processing_status' => 'completed', 'count' => 5],
            ]);

        // WHEN we run the command (with auto-confirm)
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully migrated', $output);
        $this->assertStringContainsString('10', $output);
    }

    public function testExecuteMigratesPendingStatus(): void
    {
        // GIVEN 7 documents with 'pending' status
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 7); // uploaded: 0, pending: 7

        // EXPECT transaction
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('commit');

        // EXPECT status update for 'pending' → 'queued'
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE documents SET processing_status'),
                $this->callback(function ($params) {
                    return $params['new_status'] === 'queued'
                        && $params['old_status'] === 'pending';
                })
            )
            ->willReturn(7);

        // Mock status distribution
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['processing_status' => 'queued', 'count' => 7],
            ]);

        // WHEN we run the command
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully migrated', $output);
    }

    public function testExecuteMigratesBothStatuses(): void
    {
        // GIVEN 5 uploaded and 8 pending documents
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(5, 8); // uploaded: 5, pending: 8

        // EXPECT transaction
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('commit');

        // EXPECT two status updates
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(5, 8);

        // Mock status distribution
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['processing_status' => 'queued', 'count' => 13],
            ]);

        // WHEN we run the command
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command succeeds and shows total
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully migrated 13', $output);
    }

    public function testExecuteRollsBackOnError(): void
    {
        // GIVEN documents to migrate
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(5, 0);

        // EXPECT transaction started
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        // AND migration fails
        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException('Database error'));

        // EXPECT rollback
        $this->connection->expects($this->once())
            ->method('rollBack');

        // WHEN we run the command
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command fails
        $this->assertEquals(1, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Migration failed', $output);
    }

    public function testExecuteCancelsOnUserDecline(): void
    {
        // GIVEN documents to migrate
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(5, 3);

        // EXPECT no transaction when user declines
        $this->connection->expects($this->never())
            ->method('beginTransaction');

        $this->connection->expects($this->never())
            ->method('executeStatement');

        // WHEN user answers 'no' to confirmation
        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([]);

        // THEN command exits successfully without changes
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('cancelled', $output);
    }

    public function testExecuteDisplaysStatusDistribution(): void
    {
        // GIVEN documents to migrate
        $this->connection->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(5, 0);

        // Mock successful migration
        $this->connection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->expects($this->once())
            ->method('commit');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(5);

        // EXPECT status distribution query
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['processing_status' => 'queued', 'count' => 15],
                ['processing_status' => 'processing', 'count' => 3],
                ['processing_status' => 'completed', 'count' => 100],
                ['processing_status' => 'failed', 'count' => 2],
            ]);

        // WHEN we run the command
        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        // THEN output includes status distribution
        $this->assertEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Current Status Distribution', $output);
        $this->assertStringContainsString('queued', $output);
        $this->assertStringContainsString('processing', $output);
        $this->assertStringContainsString('completed', $output);
        $this->assertStringContainsString('failed', $output);
    }

    public function testCommandHasCorrectName(): void
    {
        $this->assertEquals('app:migrate-document-status', $this->command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $description = $this->command->getDescription();
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('migrate', strtolower($description));
    }
}
