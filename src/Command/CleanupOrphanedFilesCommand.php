<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OrphanedFileCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to cleanup orphaned files in the document storage system
 *
 * Usage:
 *   php bin/console app:cleanup-orphaned-files --dry-run  # Preview what would be deleted
 *   php bin/console app:cleanup-orphaned-files            # Actually delete orphaned files
 *   php bin/console app:cleanup-orphaned-files --stats    # Show cleanup statistics
 */
#[AsCommand(
    name: 'app:cleanup-orphaned-files',
    description: 'Cleanup orphaned files in the document storage system',
)]
class CleanupOrphanedFilesCommand extends Command
{
    public function __construct(
        private readonly OrphanedFileCleanupService $cleanupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview what would be deleted without actually deleting files'
            )
            ->addOption(
                'stats',
                's',
                InputOption::VALUE_NONE,
                'Show cleanup statistics without performing any operations'
            )
            ->setHelp(
                <<<'HELP'
                The <info>app:cleanup-orphaned-files</info> command helps maintain the document storage system by finding and removing orphaned files.

                <comment>Orphaned files</comment> are files that exist on disk but have no corresponding database record.
                This can happen when:
                - Database transaction fails after file upload
                - Manual file system operations
                - Database rollbacks

                <info>Usage Examples:</info>

                  # Preview orphaned files without deleting (recommended first step)
                  <info>php bin/console app:cleanup-orphaned-files --dry-run</info>

                  # Show statistics about files and potential cleanup
                  <info>php bin/console app:cleanup-orphaned-files --stats</info>

                  # Actually delete orphaned files
                  <info>php bin/console app:cleanup-orphaned-files</info>

                <comment>Safety Features:</comment>
                - Dry-run mode allows safe previewing
                - Detailed reporting of all actions
                - Error handling for failed deletions
                - Ignores system files (.DS_Store, Thumbs.db, etc.)
                HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Document Storage Cleanup');

        // Show statistics if requested
        if ($input->getOption('stats')) {
            return $this->showStatistics($io);
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode. No files will be deleted.');
        } else {
            $io->warning('This will permanently delete orphaned files from the filesystem!');

            if (!$io->confirm('Are you sure you want to continue?', false)) {
                $io->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $io->section('Finding orphaned files...');

        // Perform cleanup
        $result = $this->cleanupService->cleanupOrphanedFiles($dryRun);

        // Display results
        $io->section('Cleanup Results');

        $io->writeln(sprintf('Total orphaned files found: <info>%d</info>', count($result['orphanedFiles'])));

        if (!empty($result['orphanedFiles'])) {
            $io->writeln('');
            $io->writeln('<comment>Orphaned files:</comment>');

            foreach ($result['orphanedFiles'] as $file) {
                $status = $dryRun ? '[would delete]' : ($result['filesDeleted'] > 0 ? '[deleted]' : '[skipped]');
                $io->writeln(sprintf('  %s %s', $status, $file));
            }
        }

        $io->writeln('');

        if ($dryRun) {
            $io->success(sprintf(
                'DRY-RUN complete. %d file(s) would be deleted. Run without --dry-run to actually delete them.',
                count($result['orphanedFiles'])
            ));
        } else {
            $io->writeln(sprintf('Files deleted: <info>%d</info>', $result['filesDeleted']));

            if (!empty($result['errors'])) {
                $io->error('Some errors occurred during cleanup:');
                foreach ($result['errors'] as $error) {
                    $io->writeln(sprintf('  - %s', $error));
                }
                return Command::FAILURE;
            }

            $io->success(sprintf('Cleanup complete. %d orphaned file(s) were deleted.', $result['filesDeleted']));
        }

        // Show recommendation
        if (count($result['orphanedFiles']) === 0) {
            $io->note('No orphaned files found. Your document storage is clean!');
        }

        return Command::SUCCESS;
    }

    private function showStatistics(SymfonyStyle $io): int
    {
        $io->section('Storage Statistics');

        $stats = $this->cleanupService->getCleanupStatistics();

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total files on disk', $stats['totalFilesOnDisk']],
                ['Total documents in database', $stats['totalDocumentsInDatabase']],
                ['Orphaned files (on disk, not in DB)', $stats['orphanedFilesCount']],
                ['Missing files (in DB, not on disk)', $stats['missingFilesCount']],
            ]
        );

        if ($stats['orphanedFilesCount'] > 0) {
            $io->note(sprintf(
                'Found %d orphaned file(s). Run this command without --stats to clean them up.',
                $stats['orphanedFilesCount']
            ));
        } else {
            $io->success('No orphaned files found!');
        }

        if ($stats['missingFilesCount'] > 0) {
            $io->warning(sprintf(
                'Found %d database record(s) pointing to missing files. ' .
                'These may need manual investigation or database cleanup.',
                $stats['missingFilesCount']
            ));

            $io->writeln('<comment>Missing files:</comment>');
            $missingFiles = $this->cleanupService->findMissingFiles();
            foreach ($missingFiles as $filePath => $documentId) {
                $io->writeln(sprintf('  - %s (Document ID: %s)', $filePath, $documentId));
            }
        }

        return Command::SUCCESS;
    }
}
