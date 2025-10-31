<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\WebhookConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Webhook Notification Service
 *
 * Sends webhook notifications for document processing events
 */
class WebhookNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Notify webhooks about document processing completion
     *
     * @param Document $document
     * @return void
     */
    public function notifyProcessingComplete(Document $document): void
    {
        $this->notifyWebhooks('document.processing.completed', [
            'document_id' => $document->getId(),
            'filename' => $document->getOriginalName(),
            'status' => $document->getProcessingStatus(),
            'ocr_confidence' => $document->getConfidenceScore(),
            'category' => $document->getCategory()?->getName(),
            'extracted_date' => $document->getExtractedDate()?->format('Y-m-d'),
            'extracted_amount' => $document->getExtractedAmount(),
            'language' => $document->getLanguage(),
            'completed_at' => (new \DateTime())->format('c')
        ]);
    }

    /**
     * Notify webhooks about document processing failure
     *
     * @param Document $document
     * @return void
     */
    public function notifyProcessingFailed(Document $document): void
    {
        $this->notifyWebhooks('document.processing.failed', [
            'document_id' => $document->getId(),
            'filename' => $document->getOriginalName(),
            'status' => $document->getProcessingStatus(),
            'error' => $document->getProcessingError(),
            'failed_at' => (new \DateTime())->format('c')
        ]);
    }

    /**
     * Send notifications to configured webhooks
     *
     * @param string $event
     * @param array $payload
     * @return void
     */
    private function notifyWebhooks(string $event, array $payload): void
    {
        // Find active webhooks that listen to this event
        $webhooks = $this->entityManager
            ->getRepository(WebhookConfig::class)
            ->createQueryBuilder('w')
            ->where('w.active = :active')
            ->andWhere('JSON_CONTAINS(w.events, :event) = 1')
            ->setParameter('active', true)
            ->setParameter('event', json_encode($event))
            ->getQuery()
            ->getResult();

        foreach ($webhooks as $webhook) {
            $this->sendWebhook($webhook, $event, $payload);
        }
    }

    /**
     * Send webhook notification
     *
     * @param WebhookConfig $webhook
     * @param string $event
     * @param array $payload
     * @return void
     */
    private function sendWebhook(WebhookConfig $webhook, string $event, array $payload): void
    {
        try {
            $body = [
                'event' => $event,
                'timestamp' => (new \DateTime())->format('c'),
                'data' => $payload
            ];

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'DocVault-Webhook/1.0'
            ];

            // Add signature if secret is configured
            if ($webhook->getSecret()) {
                $signature = hash_hmac('sha256', json_encode($body), $webhook->getSecret());
                $headers['X-Webhook-Signature'] = $signature;
            }

            $response = $this->httpClient->request('POST', $webhook->getUrl(), [
                'headers' => $headers,
                'json' => $body,
                'timeout' => 10
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Webhook notification sent successfully', [
                    'webhook_id' => $webhook->getId(),
                    'event' => $event,
                    'status_code' => $statusCode
                ]);
            } else {
                $this->logger->warning('Webhook notification returned non-success status', [
                    'webhook_id' => $webhook->getId(),
                    'event' => $event,
                    'status_code' => $statusCode
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to send webhook notification', [
                'webhook_id' => $webhook->getId(),
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }
}
