<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Password Reset Token entity
 *
 * Manages secure tokens for password reset functionality with expiration
 * and usage tracking to prevent replay attacks
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\Index(columns: ['token'], name: 'idx_password_reset_token')]
#[ORM\Index(columns: ['user_id'], name: 'idx_password_reset_user')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_password_reset_expires')]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isUsed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function setIsUsed(bool $isUsed): static
    {
        $this->isUsed = $isUsed;

        if ($isUsed && $this->usedAt === null) {
            $this->usedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): static
    {
        $this->usedAt = $usedAt;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Check if the token has expired
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    /**
     * Check if the token is valid (not expired and not used)
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }

    /**
     * Mark the token as used and record usage metadata
     */
    public function markAsUsed(?string $ipAddress = null, ?string $userAgent = null): static
    {
        $this->setIsUsed(true);
        $this->setUsedAt(new \DateTimeImmutable());

        if ($ipAddress !== null) {
            $this->setIpAddress($ipAddress);
        }

        if ($userAgent !== null) {
            $this->setUserAgent($userAgent);
        }

        return $this;
    }

    /**
     * Generate a secure random token
     */
    public static function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32)); // 64-character hex string
    }

    /**
     * Create a new password reset token for a user
     */
    public static function createForUser(User $user, int $expirationHours = 1): static
    {
        $token = new static();
        $token->setUser($user);
        $token->setToken(static::generateSecureToken());
        $token->setExpiresAt(new \DateTimeImmutable("+{$expirationHours} hours"));

        return $token;
    }

    /**
     * Get time remaining until expiration in seconds
     */
    public function getTimeUntilExpiration(): int
    {
        $now = new \DateTimeImmutable();
        if ($this->isExpired()) {
            return 0;
        }

        return $this->expiresAt->getTimestamp() - $now->getTimestamp();
    }

    /**
     * Get formatted expiration time for display
     */
    public function getFormattedExpirationTime(): string
    {
        return $this->expiresAt->format('Y-m-d H:i:s T');
    }
}