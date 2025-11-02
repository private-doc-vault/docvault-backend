<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-document-metadata',
    description: 'Flatten document metadata structure by removing "extracted_metadata" nesting'
)]
class MigrateDocumentMetadataCommand extends Command
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
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of documents to process in each batch',
                100
            )
            ->setHelp(
                <<<'HELP'
This command flattens document metadata structure by removing unnecessary nesting.

Metadata structure transformation:
  Before: {"ocr_task_id": "...", "extracted_metadata": {"dates": [...], "amounts": [...]}}
  After:  {"ocr_task_id": "...", "dates": [...], "amounts": [...]}

The command will:
  1. Find all documents with nested "extracted_metadata" structure
  2. Move all fields from "extracted_metadata" to the top level
  3. Remove the "extracted_metadata" wrapper
  4. Preserve all other metadata fields

Use --dry-run to preview changes without modifying the database.
Use --batch-size to control memory usage for large datasets.

Examples:
  php bin/console app:migrate-document-metadata --dry-run
  php bin/console app:migrate-document-metadata --batch-size=50
  php bin/console app:migrate-document-metadata
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Document Metadata Migration');

        if ($isDryRun) {
            $io->note('DRY RUN MODE - No changes will be made to the database');
        }

        try {
            // Find all documents with nested metadata
            $repository = $this->entityManager->getRepository(Document::class);
            $allDocuments = $repository->findAll();

            $documentsToMigrate = [];
            $alreadyFlat = 0;

            $io->section('Analyzing Documents');
            $io->text('Scanning for documents with nested metadata...');

            foreach ($allDocuments as $document) {
                $metadata = $document->getMetadata();

                if ($metadata === null || empty($metadata)) {
                    continue;
                }

                // Check if metadata has nested "extracted_metadata"
                if (isset($metadata['extracted_metadata']) && is_array($metadata['extracted_metadata'])) {
                    $documentsToMigrate[] = $document;
                } else {
                    $alreadyFlat++;
                }
            }

            $totalDocuments = count($allDocuments);
            $needsMigration = count($documentsToMigrate);

            // Display summary
            $io->section('Migration Summary');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total documents', $totalDocuments],
                    ['Already flat', $alreadyFlat],
                    ['Needs migration', $needsMigration],
                ]
            );

            if ($needsMigration === 0) {
                $io->success('No documents need migration. All metadata is already in flat structure.');
                return Command::SUCCESS;
            }

            if ($isDryRun) {
                $io->section('Sample Transformations (Dry Run)');
                $samplesToShow = min(3, $needsMigration);

                for ($i = 0; $i < $samplesToShow; $i++) {
                    $document = $documentsToMigrate[$i];
                    $oldMetadata = $document->getMetadata();
                    $newMetadata = $this->flattenMetadata($oldMetadata);

                    $io->writeln(sprintf('<comment>Document ID:</comment> %s', $document->getId()));
                    $io->writeln('<comment>Before:</comment>');
                    $io->writeln(json_encode($oldMetadata, JSON_PRETTY_PRINT));
                    $io->writeln('<comment>After:</comment>');
                    $io->writeln(json_encode($newMetadata, JSON_PRETTY_PRINT));
                    $io->newLine();
                }

                $io->warning(sprintf(
                    'This is a dry run. To apply changes, run without --dry-run option. %d documents would be migrated.',
                    $needsMigration
                ));

                return Command::SUCCESS;
            }

            // Confirm before proceeding
            if (!$io->confirm(sprintf('Migrate %d documents to flat metadata structure?', $needsMigration), false)) {
                $io->comment('Migration cancelled.');
                return Command::SUCCESS;
            }

            // Perform migration in batches
            $io->section('Migrating Metadata');
            $io->progressStart($needsMigration);

            $migratedCount = 0;
            $errorCount = 0;
            $batchCount = 0;

            foreach ($documentsToMigrate as $document) {
                try {
                    $oldMetadata = $document->getMetadata();
                    $newMetadata = $this->flattenMetadata($oldMetadata);

                    $document->setMetadata($newMetadata);

                    $batchCount++;
                    $migratedCount++;

                    // Flush in batches to manage memory
                    if ($batchCount >= $batchSize) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                        $batchCount = 0;
                    }

                    $io->progressAdvance();

                } catch (\Exception $e) {
                    $errorCount++;
                    $io->error(sprintf(
                        'Failed to migrate document %s: %s',
                        $document->getId(),
                        $e->getMessage()
                    ));
                }
            }

            // Flush remaining documents
            if ($batchCount > 0) {
                $this->entityManager->flush();
            }

            $io->progressFinish();
            $io->newLine();

            if ($errorCount > 0) {
                $io->warning(sprintf(
                    'Migration completed with %d errors. Successfully migrated %d documents.',
                    $errorCount,
                    $migratedCount
                ));
                return Command::FAILURE;
            }

            $io->success(sprintf('Successfully migrated %d documents to flat metadata structure!', $migratedCount));

            // Display verification stats
            $this->displayVerificationStats($io);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Migration failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Flatten metadata by moving fields from "extracted_metadata" to top level
     */
    private function flattenMetadata(array $metadata): array
    {
        if (!isset($metadata['extracted_metadata']) || !is_array($metadata['extracted_metadata'])) {
            return $metadata;
        }

        $extractedMetadata = $metadata['extracted_metadata'];
        unset($metadata['extracted_metadata']);

        // Merge extracted metadata fields at top level
        // Fields from top level take precedence over extracted_metadata
        $metadata = array_merge($extractedMetadata, $metadata);

        return $metadata;
    }

    /**
     * Display verification statistics after migration
     */
    private function displayVerificationStats(SymfonyStyle $io): void
    {
        $io->section('Verification');

        $repository = $this->entityManager->getRepository(Document::class);
        $allDocuments = $repository->findAll();

        $flatCount = 0;
        $nestedCount = 0;
        $emptyCount = 0;

        foreach ($allDocuments as $document) {
            $metadata = $document->getMetadata();

            if ($metadata === null || empty($metadata)) {
                $emptyCount++;
                continue;
            }

            if (isset($metadata['extracted_metadata'])) {
                $nestedCount++;
            } else {
                $flatCount++;
            }
        }

        $io->table(
            ['Status', 'Count'],
            [
                ['Flat structure', $flatCount],
                ['Still nested', $nestedCount],
                ['No metadata', $emptyCount],
                ['Total', count($allDocuments)],
            ]
        );

        if ($nestedCount > 0) {
            $io->warning(sprintf('%d documents still have nested structure. Consider running migration again.', $nestedCount));
        } else {
            $io->success('All documents now use flat metadata structure!');
        }
    }
}
