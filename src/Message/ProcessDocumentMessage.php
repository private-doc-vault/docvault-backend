<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to trigger document processing
 */
final class ProcessDocumentMessage
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
