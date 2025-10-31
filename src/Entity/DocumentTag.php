<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_tags')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['name'], name: 'idx_tag_name')]
#[ORM\Index(columns: ['slug'], name: 'idx_tag_slug')]
#[ORM\Index(columns: ['usage_count'], name: 'idx_tag_usage')]
class DocumentTag
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $usageCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToMany(targetEntity: Document::class, mappedBy: 'tags')]
    private Collection $documents;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->documents = new ArrayCollection();
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

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        $this->usageCount = $usageCount;
        return $this;
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

    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->addTag($this);
        }

        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            $document->removeTag($this);
        }

        return $this;
    }

    public function hasDocument(Document $document): bool
    {
        return $this->documents->contains($document);
    }

    public function incrementUsageCount(): static
    {
        $this->usageCount++;
        return $this;
    }

    public function decrementUsageCount(): static
    {
        if ($this->usageCount > 0) {
            $this->usageCount--;
        }
        return $this;
    }

    public function updateUsageCount(): static
    {
        $this->usageCount = $this->documents->count();
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

    public function equals(DocumentTag $other): bool
    {
        return $this->name === $other->getName() && $this->slug === $other->getSlug();
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }

    // Static helper methods

    public static function normalizeName(string $name): string
    {
        return trim(strtolower($name));
    }

    public static function generateSlug(string $name): string
    {
        $slug = self::normalizeName($name);
        $slug = preg_replace('/[^a-z0-9\s\-_\/]/', '', $slug);
        $slug = preg_replace('/[\s\-_\/]+/', '-', $slug);
        return trim($slug, '-');
    }

    public static function validateName(string $name): bool
    {
        $normalizedName = self::normalizeName($name);
        
        // Check length (2-50 characters)
        if (strlen($normalizedName) < 2 || strlen($normalizedName) > 50) {
            return false;
        }
        
        // Check for spaces (tags should not contain spaces in this implementation)
        if (strpos($normalizedName, ' ') !== false) {
            return false;
        }
        
        // Check for valid characters (alphanumeric, hyphens, underscores)
        if (!preg_match('/^[a-z0-9\-_]+$/', $normalizedName)) {
            return false;
        }
        
        return true;
    }

    /**
     * @param DocumentTag[] $tags
     * @return DocumentTag[]
     */
    public static function getTagsByUsage(array $tags): array
    {
        usort($tags, function (DocumentTag $a, DocumentTag $b) {
            return $b->getUsageCount() <=> $a->getUsageCount();
        });
        
        return $tags;
    }

    /**
     * @param DocumentTag[] $tags
     * @return DocumentTag[]
     */
    public static function getTagsByName(array $tags): array
    {
        usort($tags, function (DocumentTag $a, DocumentTag $b) {
            return strcmp($a->getName() ?? '', $b->getName() ?? '');
        });
        
        return $tags;
    }

    public static function createFromString(string $name, ?User $createdBy = null): DocumentTag
    {
        $tag = new self();
        $normalizedName = self::normalizeName($name);
        
        $tag->setName($normalizedName);
        $tag->setSlug(self::generateSlug($normalizedName));
        $tag->setCreatedBy($createdBy);
        $tag->setIsActive(true);
        $tag->setIsSystem(false);
        
        return $tag;
    }
}