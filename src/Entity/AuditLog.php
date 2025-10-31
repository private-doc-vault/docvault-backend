<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['action'], name: 'idx_auditlog_action')]
#[ORM\Index(columns: ['resource'], name: 'idx_auditlog_resource')]
#[ORM\Index(columns: ['level'], name: 'idx_auditlog_level')]
#[ORM\Index(columns: ['created_at'], name: 'idx_auditlog_created')]
#[ORM\Index(columns: ['user_id'], name: 'idx_auditlog_user')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $action = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $resource = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'info'])]
    private string $level = 'info';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'auditLogs')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Document $document = null;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $metadata = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
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

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function setResource(?string $resource): static
    {
        $this->resource = $resource;
        return $this;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): static
    {
        $this->resourceId = $resourceId;
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

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getMetadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
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

    // Helper methods for action parsing

    public function getActionType(): ?string
    {
        if (!$this->action) {
            return null;
        }

        $parts = explode('.', $this->action);
        return $parts[0] ?? null;
    }

    public function getActionName(): ?string
    {
        if (!$this->action) {
            return null;
        }

        $parts = explode('.', $this->action);
        return $parts[1] ?? null;
    }

    // Helper methods for level checking

    public function isLevel(string $level): bool
    {
        return $this->level === $level;
    }

    public function isInfo(): bool
    {
        return $this->isLevel('info');
    }

    public function isWarning(): bool
    {
        return $this->isLevel('warning');
    }

    public function isError(): bool
    {
        return $this->isLevel('error');
    }

    public function __toString(): string
    {
        $parts = [];
        
        if ($this->action) {
            $parts[] = $this->action;
        }
        
        if ($this->user) {
            $parts[] = 'by ' . $this->user->getEmail();
        }
        
        $result = implode(' ', $parts);
        
        if ($this->description) {
            $result .= ($result ? ': ' : '') . $this->description;
        }
        
        return $result;
    }

    // Static helper methods

    public static function validateAction(string $action): bool
    {
        // Action format: resource.action (e.g., document.view, user.create)
        if (empty($action)) {
            return false;
        }
        
        // Must contain at least one dot
        if (strpos($action, '.') === false) {
            return false;
        }
        
        // Cannot start or end with a dot
        if (str_starts_with($action, '.') || str_ends_with($action, '.')) {
            return false;
        }
        
        // Cannot contain consecutive dots
        if (strpos($action, '..') !== false) {
            return false;
        }
        
        // Must match the pattern: letters/numbers/underscores separated by dots
        if (!preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)+$/', $action)) {
            return false;
        }
        
        return true;
    }

    public static function validateLevel(string $level): bool
    {
        $validLevels = ['debug', 'info', 'warning', 'error'];
        return in_array($level, $validLevels, true);
    }

    public static function createForDocument(string $actionName, User $user, Document $document, ?string $description = null, string $level = 'info'): static
    {
        $auditLog = new self();
        $auditLog->setAction('document.' . $actionName);
        $auditLog->setResource('Document');
        $auditLog->setResourceId($document->getId());
        $auditLog->setUser($user);
        $auditLog->setDocument($document);
        $auditLog->setDescription($description);
        $auditLog->setLevel($level);
        $auditLog->setCreatedAt(new \DateTimeImmutable());
        
        return $auditLog;
    }

    public static function createForUser(string $actionName, User $user, User $targetUser, ?string $description = null, string $level = 'info'): static
    {
        $auditLog = new self();
        $auditLog->setAction('user.' . $actionName);
        $auditLog->setResource('User');
        $auditLog->setResourceId($targetUser->getId());
        $auditLog->setUser($user);
        $auditLog->setDescription($description);
        $auditLog->setLevel($level);
        $auditLog->setCreatedAt(new \DateTimeImmutable());
        
        return $auditLog;
    }

    public static function createSystemLog(string $actionName, ?string $description = null, string $level = 'info'): static
    {
        $auditLog = new self();
        $auditLog->setAction('system.' . $actionName);
        $auditLog->setResource('System');
        $auditLog->setDescription($description);
        $auditLog->setLevel($level);
        $auditLog->setCreatedAt(new \DateTimeImmutable());
        
        return $auditLog;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }
}