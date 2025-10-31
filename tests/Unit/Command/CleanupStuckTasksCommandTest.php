<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CleanupStuckTasksCommand;
use App\Service\OcrApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for CleanupStuckTasksCommand
 *
 * Following TDD approach - tests written first, then implementation
 * Task 4.4: Write tests for CleanupStuckTasksCommand
 */
class CleanupStuckTasksCommandTest extends TestCase
{
    private OcrApiClient $ocrApiClient;
    private LoggerInterface $logger;
    private CleanupStuckTasksCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->ocrApiClient = $this->createMock(OcrApiClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->command = new CleanupStuckTasksCommand(
            $this->ocrApiClient,
            $this->logger
        );

        // Set up command tester
        $application = new Application();
        $application->add($this->command);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testExecuteFindsAndResetsStuckTasks(): void
    {
        // GIVEN: OCR service reports 3 stuck tasks
        $stuckTasks = [
            'task-123',
            'task-456',
            'task-789',
        ];

        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->with(30) // Default timeout of 30 minutes
            ->willReturn($stuckTasks);

        // EXPECT: Each stuck task to be reset
        $this->ocrApiClient->expects($this->exactly(3))
            ->method('resetStuckTask')
            ->willReturnOnConsecutiveCalls(true, true, true);

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Command should succeed
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Found 3 stuck task(s)', $output);
        $this->assertStringContainsString('Successfully reset 3 task(s)', $output);
    }

    public function testExecuteWithNoStuckTasks(): void
    {
        // GIVEN: No stuck tasks found
        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->with(30)
            ->willReturn([]);

        // EXPECT: No reset calls
        $this->ocrApiClient->expects($this->never())
            ->method('resetStuckTask');

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should succeed with appropriate message
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No stuck tasks found', $output);
    }

    public function testExecuteWithCustomTimeout(): void
    {
        // GIVEN: Custom timeout specified
        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->with(60) // Custom 60 minute timeout
            ->willReturn(['task-123']);

        $this->ocrApiClient->expects($this->once())
            ->method('resetStuckTask')
            ->willReturn(true);

        // WHEN: Command executed with custom timeout
        $this->commandTester->execute(['--timeout' => 60]);

        // THEN: Should use custom timeout
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Using timeout: 60 minutes', $output);
    }

    public function testExecuteInDryRunMode(): void
    {
        // GIVEN: Stuck tasks exist
        $stuckTasks = ['task-123', 'task-456'];

        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->willReturn($stuckTasks);

        // EXPECT: No reset calls in dry-run mode
        $this->ocrApiClient->expects($this->never())
            ->method('resetStuckTask');

        // WHEN: Command executed in dry-run mode
        $this->commandTester->execute(['--dry-run' => true]);

        // THEN: Should preview without resetting
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('DRY-RUN mode', $output);
        $this->assertStringContainsString('Found 2 stuck task(s)', $output);
        $this->assertStringContainsString('would be reset', $output);
    }

    public function testExecuteHandlesPartialFailures(): void
    {
        // GIVEN: 3 stuck tasks, but one fails to reset
        $stuckTasks = ['task-123', 'task-456', 'task-789'];

        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->willReturn($stuckTasks);

        // AND: Second task fails to reset
        $this->ocrApiClient->expects($this->exactly(3))
            ->method('resetStuckTask')
            ->willReturnOnConsecutiveCalls(true, false, true);

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should report partial success
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Successfully reset 2 task(s)', $output);
        $this->assertStringContainsString('Failed to reset 1 task(s)', $output);
    }

    public function testExecuteHandlesOcrServiceError(): void
    {
        // GIVEN: OCR service throws an error
        $this->ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->willThrowException(new \RuntimeException('OCR service unavailable'));

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should fail gracefully
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Error communicating with OCR service', $output);
        $this->assertStringContainsString('OCR service unavailable', $output);
    }

    public function testExecuteLogsStuckTaskDetails(): void
    {
        // GIVEN: Multiple stuck tasks
        $stuckTasks = ['task-123', 'task-456'];

        $this->ocrApiClient->method('findStuckTasks')->willReturn($stuckTasks);
        $this->ocrApiClient->method('resetStuckTask')->willReturn(true);

        // EXPECT: Logger to be called for each stuck task
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('Resetting stuck task'));

        // WHEN: Command is executed
        $this->commandTester->execute([]);

        // THEN: Should succeed
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteWithVerboseOutput(): void
    {
        // GIVEN: Stuck tasks exist
        $stuckTasks = ['task-123', 'task-456'];

        $this->ocrApiClient->method('findStuckTasks')->willReturn($stuckTasks);
        $this->ocrApiClient->method('resetStuckTask')->willReturn(true);

        // WHEN: Command executed in verbose mode
        $this->commandTester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // THEN: Should show detailed output
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('task-123', $output);
        $this->assertStringContainsString('task-456', $output);
    }

    public function testExecuteValidatesTimeoutParameter(): void
    {
        // GIVEN: Invalid timeout specified
        // WHEN: Command executed with invalid timeout
        $this->commandTester->execute(['--timeout' => -10]);

        // THEN: Should fail with validation error
        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Timeout must be a positive integer', $output);
    }

    public function testCommandConfiguration(): void
    {
        // THEN: Command should have proper configuration
        $this->assertEquals('app:cleanup-stuck-tasks', $this->command->getName());
        $this->assertStringContainsString('stuck tasks', $this->command->getDescription());

        // AND: Should have timeout option
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('timeout'));
        $this->assertTrue($definition->hasOption('dry-run'));
    }
}
