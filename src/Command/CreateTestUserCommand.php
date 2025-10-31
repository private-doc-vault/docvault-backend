<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-test-user',
    description: 'Creates a test user for integration tests'
)]
class CreateTestUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Check if test user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);

        if ($existingUser) {
            $output->writeln('Test user already exists');
            return Command::SUCCESS;
        }

        // Create test user
        $user = new User();
        $user->setId(Uuid::uuid4()->toString());
        $user->setEmail('test@example.com');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setRoles(['ROLE_USER']);

        // Hash password 'password123'
        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);

        // Set timestamps
        $now = new \DateTimeImmutable();
        $user->setCreatedAt($now);
        $user->setUpdatedAt($now);

        // Persist user
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('Test user created: test@example.com / password123');

        return Command::SUCCESS;
    }
}