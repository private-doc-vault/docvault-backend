<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
#[ORM\HasLifecycleCallbacks]
class Document
{
    // Status constants - matches docs/architecture/status-enumeration.md
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $filename = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'bigint')]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'text')]
    private ?string $filePath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ocrText = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $processingStatus = self::STATUS_QUEUED;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $processingError = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $progress = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $currentOperation = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $thumbnailPath = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $versionNumber = 1;

    #[ORM\Column(type: 'boolean')]
    private bool $archived = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $extractedDate = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $extractedAmount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $searchableContent = null;

    #[ORM\Column(type: 'string', length: 5)]
    private string $language = 'en';

    #[ORM\Column(type: 'decimal', precision: 3, scale: 2, nullable: true)]
    private ?string $confidenceScore = null;

    #[ORM\ManyToMany(targetEntity: DocumentTag::class, inversedBy: 'documents')]
    #[ORM\JoinTable(name: 'document_document_tags')]
    private Collection $tags;
    
    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $uploadedBy = null;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->tags = new ArrayCollection();
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

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $originalName;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getOcrText(): ?string
    {
        return $this->ocrText;
    }

    public function setOcrText(?string $ocrText): self
    {
        $this->ocrText = $ocrText;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getProcessingStatus(): string
    {
        return $this->processingStatus;
    }

    public function setProcessingStatus(string $processingStatus): self
    {
        $this->processingStatus = $processingStatus;
        return $this;
    }

    public function getProcessingError(): ?string
    {
        return $this->processingError;
    }

    public function setProcessingError(?string $processingError): self
    {
        $this->processingError = $processingError;
        return $this;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function setProgress(?int $progress): self
    {
        if ($progress !== null && ($progress < 0 || $progress > 100)) {
            throw new \InvalidArgumentException('Progress must be between 0 and 100');
        }
        $this->progress = $progress;
        return $this;
    }

    public function getCurrentOperation(): ?string
    {
        return $this->currentOperation;
    }

    public function setCurrentOperation(?string $currentOperation): self
    {
        $this->currentOperation = $currentOperation;
        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): self
    {
        $this->thumbnailPath = $thumbnailPath;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(DocumentTag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(DocumentTag $tag): static
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;
        return $this;
    }

    public function getExtractedDate(): ?\DateTimeImmutable
    {
        return $this->extractedDate;
    }

    public function setExtractedDate(?\DateTimeImmutable $extractedDate): self
    {
        $this->extractedDate = $extractedDate;
        return $this;
    }

    public function getExtractedAmount(): ?string
    {
        return $this->extractedAmount;
    }

    public function setExtractedAmount(?string $extractedAmount): self
    {
        $this->extractedAmount = $extractedAmount;
        return $this;
    }

    public function getSearchableContent(): ?string
    {
        return $this->searchableContent;
    }

    public function setSearchableContent(?string $searchableContent): self
    {
        $this->searchableContent = $searchableContent;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getConfidenceScore(): ?string
    {
        return $this->confidenceScore;
    }

    public function setConfidenceScore(?string $confidenceScore): self
    {
        $this->confidenceScore = $confidenceScore;
        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function __toString(): string
    {
        if ($this->originalName) {
            return $this->originalName;
        }

        if ($this->filename) {
            return $this->filename;
        }

        return 'Document: ' . ($this->id ?? 'new');
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}