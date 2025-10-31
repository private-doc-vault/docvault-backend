<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-old-files',
    description: 'Delete files for completed documents older than specified retention period'
)]
class CleanupOldFilesCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $documentsBasePath,
        private readonly int $defaultRetentionDays = 30
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of days to retain files after processing completion',
                $this->defaultRetentionDays
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be deleted without actually deleting files'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $retentionDays = (int) $input->getOption('retention-days');

        // Validate retention days
        if ($retentionDays < 1) {
            $io->error('Retention days must be a positive integer');
            return Command::INVALID;
        }

        if ($dryRun) {
            $io->note('DRY RUN MODE - No files will be deleted');
        }

        $io->title('Cleanup Old Files');
        $io->text(sprintf('Retention period: %d days', $retentionDays));

        // Calculate cutoff date
        $cutoffDate = new \DateTimeImmutable("-{$retentionDays} days");
        $io->text(sprintf('Deleting files from documents updated before: %s', $cutoffDate->format('Y-m-d H:i:s')));

        // Find old completed documents
        $documents = $this->documentRepository->findCompletedDocumentsOlderThan($cutoffDate);
        $totalDocuments = count($documents);

        $io->text(sprintf('Found %d document(s) eligible for cleanup', $totalDocuments));

        if ($totalDocuments === 0) {
            $io->success('No files to clean up');
            return Command::SUCCESS;
        }

        // Process each document
        $deletedCount = 0;
        $errorCount = 0;
        $io->progressStart($totalDocuments);

        foreach ($documents as $document) {
            $filePath = $document->getFilePath();

            if (!file_exists($filePath)) {
                $this->logger->warning('File not found, skipping', [
                    'document_id' => $document->getId(),
                    'file_path' => $filePath
                ]);
                $io->progressAdvance();
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  Would delete: %s (Document ID: %s)', $filePath, $document->getId()));
                $deletedCount++;
                $io->progressAdvance();
                continue;
            }

            // Attempt to delete file
            try {
                if (unlink($filePath)) {
                    $deletedCount++;
                    $this->logger->info('Deleted file for old document', [
                        'document_id' => $document->getId(),
                        'file_path' => $filePath,
                        'updated_at' => $document->getUpdatedAt()?->format('Y-m-d H:i:s')
                    ]);
                } else {
                    $errorCount++;
                    $this->logger->error('Failed to delete file', [
                        'document_id' => $document->getId(),
                        'file_path' => $filePath
                    ]);
                }
            } catch (\Throwable $e) {
                $errorCount++;
                $this->logger->error('Exception while deleting file', [
                    'document_id' => $document->getId(),
                    'file_path' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Summary
        $io->newLine();
        $io->success(sprintf(
            'Cleanup completed: %d file(s) %s, %d error(s)',
            $deletedCount,
            $dryRun ? 'would be deleted' : 'deleted',
            $errorCount
        ));

        return Command::SUCCESS;
    }
}
