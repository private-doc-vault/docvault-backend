<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_groups')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['name'], name: 'idx_usergroup_name')]
#[ORM\Index(columns: ['slug'], name: 'idx_usergroup_slug')]
class UserGroup
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'groups')]
    #[ORM\JoinTable(name: 'user_group_users')]
    private Collection $users;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->users = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;
        return $this;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function setPermissions(array $permissions): static
    {
        $this->permissions = array_unique($permissions);
        return $this;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function addPermission(string $permission): static
    {
        if (!$this->hasPermission($permission)) {
            $this->permissions[] = $permission;
        }
        return $this;
    }

    public function removePermission(string $permission): static
    {
        $this->permissions = array_values(array_filter($this->permissions, fn($p) => $p !== $permission));
        return $this;
    }

    public function getPermissionsByType(string $type): array
    {
        return array_values(array_filter($this->permissions, fn($permission) => str_starts_with($permission, $type . '.')));
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($this->permissions, $permissions));
    }

    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->permissions));
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->getGroups()->add($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            $user->getGroups()->removeElement($this);
        }

        return $this;
    }

    public function hasUser(User $user): bool
    {
        return $this->users->contains($user);
    }

    public function getUserCount(): int
    {
        return $this->users->count();
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    // Static helper methods

    public static function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\s\-_\/]/', '', $slug);
        $slug = preg_replace('/[\s\-_\/]+/', '-', $slug);
        return trim($slug, '-');
    }

    public static function validateName(string $name): bool
    {
        $trimmedName = trim($name);
        
        // Check length (2-100 characters)
        if (strlen($trimmedName) < 2 || strlen($trimmedName) > 100) {
            return false;
        }
        
        return true;
    }

    public static function validatePermission(string $permission): bool
    {
        // Permission format: resource.action (e.g., document.view, user.create)
        if (empty($permission)) {
            return false;
        }
        
        // Must contain at least one dot
        if (strpos($permission, '.') === false) {
            return false;
        }
        
        // Cannot start or end with a dot
        if (str_starts_with($permission, '.') || str_ends_with($permission, '.')) {
            return false;
        }
        
        // Cannot contain consecutive dots
        if (strpos($permission, '..') !== false) {
            return false;
        }
        
        // Must match the pattern: letters/numbers/underscores separated by dots
        if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $permission)) {
            return false;
        }
        
        return true;
    }

    public static function createSystemGroup(string $name, array $permissions = []): static
    {
        $group = new self();
        $group->setName($name);
        $group->setSlug(self::generateSlug($name));
        $group->setIsSystem(true);
        $group->setIsActive(true);
        $group->setPermissions($permissions);
        
        return $group;
    }
}