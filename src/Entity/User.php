<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $password = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $preferences = [];

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'uploadedBy')]
    private Collection $documents;

    #[ORM\ManyToMany(targetEntity: UserGroup::class, mappedBy: 'users')]
    private Collection $groups;

    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'user')]
    private Collection $auditLogs;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->documents = new ArrayCollection();
        $this->groups = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        $parts = array_filter([$this->firstName, $this->lastName]);
        return implode(' ', $parts);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // Ensure every user has ROLE_USER
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function addRole(string $role): static
    {
        if (!$this->hasRole($role)) {
            $this->roles[] = $role;
        }
        
        return $this;
    }

    public function removeRole(string $role): static
    {
        // Prevent removing ROLE_USER
        if ($role === 'ROLE_USER') {
            return $this;
        }
        
        $this->roles = array_values(array_filter($this->roles, fn($r) => $r !== $role));
        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('ROLE_ADMIN');
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPreferences(): array
    {
        return $this->preferences;
    }

    public function setPreferences(array $preferences): static
    {
        $this->preferences = $preferences;
        return $this;
    }

    public function getPreference(string $key): mixed
    {
        return $this->preferences[$key] ?? null;
    }

    public function setPreference(string $key, mixed $value): static
    {
        $this->preferences[$key] = $value;
        return $this;
    }

    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(UserGroup $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->addUser($this);
        }

        return $this;
    }

    public function removeGroup(UserGroup $group): static
    {
        if ($this->groups->contains($group)) {
            $this->groups->removeElement($group);
            $group->removeUser($this);
        }

        return $this;
    }

    public function getAuditLogs(): Collection
    {
        return $this->auditLogs;
    }

    #[\Deprecated(message: 'The method is intentionally empty as sensitive data is handled in __serialize().', since: 'symfony/security-http 7.3')]
    public function eraseCredentials(): void
    {
        // Clear sensitive data if needed
    }

    public function __toString(): string
    {
        $fullName = $this->getFullName();
        
        if ($fullName && $this->email) {
            return sprintf('%s (%s)', $fullName, $this->email);
        }
        
        return $this->email ?? '';
    }
}