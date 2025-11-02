<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Document;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reindex-documents',
    description: 'Reindex all completed documents in Meilisearch'
)]
class ReindexDocumentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchService $searchService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of documents to process in each batch',
                100
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Document Reindexing');

        // Fetch all completed documents
        $repository = $this->entityManager->getRepository(Document::class);
        $documents = $repository->findBy(['processingStatus' => 'completed']);

        $totalDocuments = count($documents);

        if ($totalDocuments === 0) {
            $io->success('No completed documents found to reindex.');
            return Command::SUCCESS;
        }

        $io->info("Reindexing {$totalDocuments} documents in batches of {$batchSize}...");

        // Create progress bar
        $progressBar = new ProgressBar($output, $totalDocuments);
        $progressBar->start();

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        // Process documents using efficient batch indexing
        try {
            // Use the new batch indexing method for better performance
            $this->searchService->indexMultipleDocumentsInChunks($documents, $batchSize);
            $successCount = $totalDocuments;

            // Update progress bar to completion
            $progressBar->advance($totalDocuments);
        } catch (\Exception $e) {
            // If batch indexing fails, fall back to individual indexing
            $io->warning('Batch indexing failed, falling back to individual indexing');

            foreach ($documents as $document) {
                try {
                    $this->searchService->indexDocument($document);
                    $successCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = [
                        'document_id' => $document->getId(),
                        'filename' => $document->getFilename(),
                        'error' => $e->getMessage()
                    ];
                }

                $progressBar->advance();
            }
        }

        // Clear entity manager to prevent memory issues
        $this->entityManager->clear();

        $progressBar->finish();
        $io->newLine(2);

        // Display results
        if ($successCount > 0) {
            $io->success("Successfully reindexed {$successCount} documents.");
        }

        if ($errorCount > 0) {
            $io->error("Failed to reindex {$errorCount} documents.");

            // Show first 10 errors
            $io->section('Errors (showing first 10):');
            foreach (array_slice($errors, 0, 10) as $error) {
                $io->text(sprintf(
                    '- %s (%s): %s',
                    $error['filename'],
                    $error['document_id'],
                    $error['error']
                ));
            }

            if (count($errors) > 10) {
                $io->text(sprintf('... and %d more errors', count($errors) - 10));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
