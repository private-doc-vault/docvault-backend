<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Minimal fixture builder that only creates entities with database-compatible fields
 */
class MinimalFixtureBuilder
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    /**
     * Create a minimal test user that works with current schema
     */
    public function createUser(array $overrides = []): User
    {
        $defaults = [
            'id' => Uuid::uuid4()->toString(),
            'email' => 'test.user@example.com',
            'password' => 'password123',
            'firstName' => 'Test',
            'lastName' => 'User',
            'isActive' => true,
            'isVerified' => true,
            'roles' => ['ROLE_USER'],
            'preferences' => [
                'theme' => 'light',
                'language' => 'en'
            ],
            'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => new \DateTimeImmutable()
        ];

        $data = array_merge($defaults, $overrides);
        
        $user = new User();
        $user->setId($data['id']);
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setRoles($data['roles']);
        $user->setIsActive($data['isActive']);
        $user->setIsVerified($data['isVerified']);
        $user->setPreferences($data['preferences']);
        $user->setCreatedAt($data['createdAt']);
        $user->setUpdatedAt($data['updatedAt']);

        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        return $user;
    }

    /**
     * Persist an entity to the test database
     */
    public function persist($entity): self
    {
        $this->entityManager->persist($entity);
        return $this;
    }

    /**
     * Flush all persisted entities
     */
    public function flush(): self
    {
        $this->entityManager->flush();
        return $this;
    }
}