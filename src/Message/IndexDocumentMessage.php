<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to trigger document indexing in Meilisearch after OCR completion
 */
final class IndexDocumentMessage
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
