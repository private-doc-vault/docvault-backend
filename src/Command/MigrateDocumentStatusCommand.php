<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-document-status',
    description: 'Migrate document status values from old terminology (uploaded, pending) to new standardized terminology (queued)'
)]
class MigrateDocumentStatusCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be changed without actually updating the database'
            )
            ->setHelp(
                <<<'HELP'
This command migrates document processing status values to use standardized terminology:

  - 'uploaded' → 'queued'
  - 'pending' → 'queued'

The standardized status values are:
  - queued: Document is in the processing queue
  - processing: Document is being processed by OCR service
  - completed: Processing finished successfully
  - failed: Processing failed with error

Use --dry-run to preview changes without modifying the database.

Examples:
  php bin/console app:migrate-document-status --dry-run
  php bin/console app:migrate-document-status
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');

        $io->title('Document Status Migration');

        if ($isDryRun) {
            $io->note('DRY RUN MODE - No changes will be made to the database');
        }

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();

        try {
            // Count documents with old status values
            $uploadedCount = $this->countDocumentsByStatus($connection, 'uploaded');
            $pendingCount = $this->countDocumentsByStatus($connection, 'pending');
            $totalToMigrate = $uploadedCount + $pendingCount;

            if ($totalToMigrate === 0) {
                $io->success('No documents need migration. All statuses are already using standardized terminology.');
                return Command::SUCCESS;
            }

            // Display summary
            $io->section('Migration Summary');
            $io->table(
                ['Old Status', 'New Status', 'Documents Affected'],
                [
                    ['uploaded', 'queued', $uploadedCount],
                    ['pending', 'queued', $pendingCount],
                    ['', 'Total', $totalToMigrate],
                ]
            );

            if ($isDryRun) {
                $io->warning('This is a dry run. To apply changes, run without --dry-run option.');
                return Command::SUCCESS;
            }

            // Confirm before proceeding
            if (!$io->confirm(sprintf('Migrate %d document status values?', $totalToMigrate), false)) {
                $io->comment('Migration cancelled.');
                return Command::SUCCESS;
            }

            // Perform migration in transaction
            $connection->beginTransaction();

            try {
                $io->section('Migrating Status Values');

                // Migrate 'uploaded' → 'queued'
                if ($uploadedCount > 0) {
                    $updatedUploaded = $this->migrateStatus($connection, 'uploaded', 'queued');
                    $io->writeln(sprintf('✓ Migrated %d documents from "uploaded" to "queued"', $updatedUploaded));
                }

                // Migrate 'pending' → 'queued'
                if ($pendingCount > 0) {
                    $updatedPending = $this->migrateStatus($connection, 'pending', 'queued');
                    $io->writeln(sprintf('✓ Migrated %d documents from "pending" to "queued"', $updatedPending));
                }

                $connection->commit();

                $io->newLine();
                $io->success(sprintf('Successfully migrated %d document status values!', $totalToMigrate));

                // Display current status distribution
                $this->displayStatusDistribution($io, $connection);

                return Command::SUCCESS;

            } catch (\Exception $e) {
                $connection->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function countDocumentsByStatus(Connection $connection, string $status): int
    {
        $sql = 'SELECT COUNT(*) FROM documents WHERE processing_status = :status';
        $result = $connection->fetchOne($sql, ['status' => $status]);

        return (int) $result;
    }

    private function migrateStatus(Connection $connection, string $oldStatus, string $newStatus): int
    {
        $sql = 'UPDATE documents SET processing_status = :new_status WHERE processing_status = :old_status';

        return $connection->executeStatement($sql, [
            'new_status' => $newStatus,
            'old_status' => $oldStatus,
        ]);
    }

    private function displayStatusDistribution(SymfonyStyle $io, Connection $connection): void
    {
        $io->section('Current Status Distribution');

        $sql = 'SELECT processing_status, COUNT(*) as count FROM documents GROUP BY processing_status ORDER BY count DESC';
        $results = $connection->fetchAllAssociative($sql);

        $tableData = [];
        $total = 0;

        foreach ($results as $row) {
            $count = (int) $row['count'];
            $total += $count;
            $tableData[] = [
                $row['processing_status'],
                $count,
            ];
        }

        $tableData[] = ['Total', $total];

        $io->table(
            ['Status', 'Count'],
            $tableData
        );
    }
}
