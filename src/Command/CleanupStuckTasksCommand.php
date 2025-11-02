<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OcrApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to cleanup stuck tasks in the OCR processing queue
 *
 * Finds tasks that have been in PROCESSING status beyond the timeout
 * threshold and resets them for retry
 *
 * Usage:
 *   php bin/console app:cleanup-stuck-tasks                     # Use default 30 minute timeout
 *   php bin/console app:cleanup-stuck-tasks --timeout=60        # Use custom timeout
 *   php bin/console app:cleanup-stuck-tasks --dry-run           # Preview without resetting
 */
#[AsCommand(
    name: 'app:cleanup-stuck-tasks',
    description: 'Find and reset stuck tasks in the OCR processing queue',
)]
class CleanupStuckTasksCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 30;

    public function __construct(
        private readonly OcrApiClient $ocrApiClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_REQUIRED,
                'Timeout threshold in minutes (default: 30)',
                self::DEFAULT_TIMEOUT_MINUTES
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview stuck tasks without resetting them'
            )
            ->setHelp(
                <<<'HELP'
                The <info>app:cleanup-stuck-tasks</info> command finds and resets tasks that are stuck in processing.

                A task is considered stuck if it has been in PROCESSING status longer than the timeout threshold.

                <info>Usage Examples:</info>

                  # Find and reset stuck tasks (default 30 minute timeout)
                  <info>php bin/console app:cleanup-stuck-tasks</info>

                  # Use custom 60 minute timeout
                  <info>php bin/console app:cleanup-stuck-tasks --timeout=60</info>

                  # Preview stuck tasks without resetting (dry-run)
                  <info>php bin/console app:cleanup-stuck-tasks --dry-run</info>

                <comment>How it works:</comment>
                1. Queries OCR service for tasks exceeding timeout threshold
                2. For each stuck task, sends reset request to OCR service
                3. OCR service re-queues the task for processing
                4. Reports success/failure statistics

                <comment>Safety Features:</comment>
                - Dry-run mode for safe previewing
                - Detailed logging of all operations
                - Graceful error handling
                - Configurable timeout threshold
                HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('OCR Task Cleanup');

        // Get and validate timeout parameter
        $timeout = (int) $input->getOption('timeout');
        if ($timeout <= 0) {
            $io->error('Timeout must be a positive integer');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode. Tasks will not be reset.');
        }

        $io->writeln(sprintf('Using timeout: <info>%d minutes</info>', $timeout));
        $io->newLine();

        // Find stuck tasks
        $io->section('Finding stuck tasks...');

        try {
            $stuckTasks = $this->ocrApiClient->findStuckTasks($timeout);
        } catch (\RuntimeException $e) {
            $io->error('Error communicating with OCR service: ' . $e->getMessage());
            $this->logger->error('Failed to find stuck tasks', [
                'error' => $e->getMessage(),
                'timeout' => $timeout,
            ]);
            return Command::FAILURE;
        }

        if (empty($stuckTasks)) {
            $io->success('No stuck tasks found. All tasks are processing normally.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found <info>%d</info> stuck task(s)', count($stuckTasks)));

        // Display stuck task IDs in verbose mode
        if ($output->isVerbose()) {
            $io->newLine();
            $io->writeln('<comment>Stuck task IDs:</comment>');
            foreach ($stuckTasks as $taskId) {
                $io->writeln(sprintf('  - %s', $taskId));
            }
        }

        if ($dryRun) {
            $io->success(sprintf(
                'DRY-RUN complete. %d task(s) would be reset. Run without --dry-run to actually reset them.',
                count($stuckTasks)
            ));
            return Command::SUCCESS;
        }

        // Reset stuck tasks
        $io->section('Resetting stuck tasks...');

        $successCount = 0;
        $failureCount = 0;

        foreach ($stuckTasks as $taskId) {
            $this->logger->warning('Resetting stuck task', [
                'task_id' => $taskId,
                'timeout_minutes' => $timeout,
            ]);

            $success = $this->ocrApiClient->resetStuckTask($taskId);

            if ($success) {
                $successCount++;
                if ($output->isVerbose()) {
                    $io->writeln(sprintf('  ✓ Reset task: <info>%s</info>', $taskId));
                }
            } else {
                $failureCount++;
                if ($output->isVerbose()) {
                    $io->writeln(sprintf('  ✗ Failed to reset task: <error>%s</error>', $taskId));
                }
            }
        }

        // Display results
        $io->newLine();
        $io->writeln(sprintf('Successfully reset <info>%d</info> task(s)', $successCount));

        if ($failureCount > 0) {
            $io->writeln(sprintf('Failed to reset <error>%d</error> task(s)', $failureCount));
            $io->warning('Some tasks could not be reset. Check logs for details.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Cleanup complete. %d stuck task(s) were reset.', $successCount));

        return Command::SUCCESS;
    }
}
