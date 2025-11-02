<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to manually retry a failed document processing task
 *
 * This message allows administrators to manually trigger retry of failed documents
 * Useful for recovering from transient failures that are now resolved
 */
final class RetryFailedTaskMessage
{
    public function __construct(
        private readonly string $documentId,
        private readonly ?string $reason = null
    ) {
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
