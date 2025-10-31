<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DatabaseSeederService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'doctrine:database:seed',
    description: 'Seed the database with default data for DocVault'
)]
class DatabaseSeedCommand extends Command
{
    public function __construct(
        private DatabaseSeederService $seederService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force seeding without confirmation')
            ->setHelp('This command seeds the database with default data including users, categories, tags, and user groups.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('DocVault Database Seeder');
        
        if (!$input->getOption('force')) {
            if (!$io->confirm('This will create default data in the database. Continue?', false)) {
                $io->info('Database seeding cancelled.');
                return Command::SUCCESS;
            }
        }

        $io->section('Seeding database with default data...');

        try {
            $this->seederService->seedAll();
            
            $io->success([
                'Database seeding completed successfully!',
                'Default data has been created:',
                '- User groups (Administrators, Editors, Viewers, Users)',
                '- Categories (General, Documents, Financial, Legal, Personal, Business)',
                '- Document tags (Important, Urgent, Archive, Draft, etc.)',
                '- Admin user (admin@docvault.local / admin123)'
            ]);

            $io->note('You can now log in with email: admin@docvault.local and password: admin123');

        } catch (\Exception $e) {
            $io->error([
                'Database seeding failed!',
                'Error: ' . $e->getMessage()
            ]);
            
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}