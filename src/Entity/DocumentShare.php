<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * DocumentShare Entity
 *
 * Represents a document sharing relationship between users.
 * Tracks who shared a document with whom, permission levels, and access history.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document_shares')]
#[ORM\Index(columns: ['shared_with_id', 'is_active'], name: 'idx_shared_with_active')]
#[ORM\Index(columns: ['document_id', 'is_active'], name: 'idx_document_active')]
class DocumentShare
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $sharedWith = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $sharedBy = null;

    /**
     * Permission level: 'view' or 'write'
     */
    #[ORM\Column(type: 'string', length: 10)]
    private string $permissionLevel = 'view';

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'integer')]
    private int $accessCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $accessedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;
        return $this;
    }

    public function getSharedWith(): ?User
    {
        return $this->sharedWith;
    }

    public function setSharedWith(?User $sharedWith): self
    {
        $this->sharedWith = $sharedWith;
        return $this;
    }

    public function getSharedBy(): ?User
    {
        return $this->sharedBy;
    }

    public function setSharedBy(?User $sharedBy): self
    {
        $this->sharedBy = $sharedBy;
        return $this;
    }

    public function getPermissionLevel(): string
    {
        return $this->permissionLevel;
    }

    public function setPermissionLevel(string $permissionLevel): self
    {
        $this->permissionLevel = $permissionLevel;
        return $this;
    }

    public function canView(): bool
    {
        return in_array($this->permissionLevel, ['view', 'write'], true);
    }

    public function canEdit(): bool
    {
        return $this->permissionLevel === 'write';
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function setAccessCount(int $accessCount): self
    {
        $this->accessCount = $accessCount;
        return $this;
    }

    public function incrementAccessCount(): self
    {
        $this->accessCount++;
        return $this;
    }

    public function getAccessedAt(): ?\DateTimeImmutable
    {
        return $this->accessedAt;
    }

    public function setAccessedAt(?\DateTimeImmutable $accessedAt): self
    {
        $this->accessedAt = $accessedAt;
        return $this;
    }

    public function markAsAccessed(): self
    {
        $this->accessedAt = new \DateTimeImmutable();
        $this->incrementAccessCount();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
