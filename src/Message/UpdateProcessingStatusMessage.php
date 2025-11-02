<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to update document processing status
 */
final class UpdateProcessingStatusMessage
{
    public function __construct(
        private readonly string $documentId
    ) {
    }

    public function getDocumentId(): string
    {
        return $this->documentId;
    }
}
