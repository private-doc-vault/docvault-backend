<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Service\OcrApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for Queue Monitoring API endpoint
 *
 * Task 4.8: Create monitoring endpoint to expose queue statistics
 */
class QueueMonitoringApiTest extends WebTestCase
{
    private function createAuthenticatedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();

        // Create a test user if needed
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'test@example.com']);
        if (!$user) {
            $user = new User();
            $user->setEmail('test@example.com');
            $user->setPassword('test');
            $em->persist($user);
            $em->flush();
        }

        // Simulate authentication
        $client->loginUser($user);

        return $client;
    }

    public function testGetQueueStatisticsReturnsSuccessfully(): void
    {
        // GIVEN: An authenticated client and mocked OCR API client
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('getQueueStatistics')
            ->willReturn([
                'queued' => 10,
                'processing' => 3,
                'failed' => 2,
                'completed_today' => 45,
                'stuck' => 1,
                'dead_letter_queue' => 5,
                'high_priority' => 2,
                'normal_priority' => 8,
                'low_priority' => 0,
            ]);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Making request to queue statistics endpoint
        $client->request('GET', '/api/monitoring/queue/statistics');

        // THEN: Should return 200 with statistics
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('queued', $data);
        $this->assertArrayHasKey('processing', $data);
        $this->assertArrayHasKey('failed', $data);
        $this->assertArrayHasKey('stuck', $data);
        $this->assertArrayHasKey('dead_letter_queue', $data);
        $this->assertEquals(10, $data['queued']);
        $this->assertEquals(3, $data['processing']);
    }

    public function testGetQueueStatisticsHandlesOcrServiceError(): void
    {
        // GIVEN: OCR service is unavailable
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('getQueueStatistics')
            ->willThrowException(new \RuntimeException('OCR service unavailable'));

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Making request to queue statistics endpoint
        $client->request('GET', '/api/monitoring/queue/statistics');

        // THEN: Should return 503 Service Unavailable
        $this->assertEquals(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('OCR service unavailable', $data['error']);
    }

    public function testGetQueueHealthReturnsHealthyStatus(): void
    {
        // GIVEN: Queue has normal processing load
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('getQueueStatistics')
            ->willReturn([
                'queued' => 10,
                'processing' => 3,
                'stuck' => 0,
                'dead_letter_queue' => 2,
            ]);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Checking queue health
        $client->request('GET', '/api/monitoring/queue/health');

        // THEN: Should return healthy status
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('healthy', $data['status']);
    }

    public function testGetQueueHealthReturnsWarningForStuckTasks(): void
    {
        // GIVEN: Queue has stuck tasks
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('getQueueStatistics')
            ->willReturn([
                'queued' => 10,
                'processing' => 3,
                'stuck' => 5,  // More than threshold
                'dead_letter_queue' => 2,
            ]);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Checking queue health
        $client->request('GET', '/api/monitoring/queue/health');

        // THEN: Should return warning status
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('warning', $data['status']);
        $this->assertArrayHasKey('issues', $data);
    }

    public function testGetQueueHealthReturnsCriticalForHighDLQ(): void
    {
        // GIVEN: Dead letter queue is overloaded
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('getQueueStatistics')
            ->willReturn([
                'queued' => 10,
                'processing' => 3,
                'stuck' => 0,
                'dead_letter_queue' => 50,  // High DLQ count
            ]);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Checking queue health
        $client->request('GET', '/api/monitoring/queue/health');

        // THEN: Should return critical status
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('critical', $data['status']);
    }

    public function testGetStuckTasksListReturnsTasks(): void
    {
        // GIVEN: OCR service reports stuck tasks
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->with(30)
            ->willReturn(['task-123', 'task-456', 'task-789']);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Requesting stuck tasks list
        $client->request('GET', '/api/monitoring/queue/stuck-tasks');

        // THEN: Should return list of stuck tasks
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('stuck_tasks', $data);
        $this->assertCount(3, $data['stuck_tasks']);
        $this->assertArrayHasKey('count', $data);
        $this->assertEquals(3, $data['count']);
    }

    public function testGetStuckTasksWithCustomTimeout(): void
    {
        // GIVEN: Custom timeout parameter
        $client = $this->createAuthenticatedClient();

        $ocrApiClient = $this->createMock(OcrApiClient::class);
        $ocrApiClient->expects($this->once())
            ->method('findStuckTasks')
            ->with(60)  // Custom timeout
            ->willReturn(['task-123']);

        static::getContainer()->set(OcrApiClient::class, $ocrApiClient);

        // WHEN: Requesting stuck tasks with custom timeout
        $client->request('GET', '/api/monitoring/queue/stuck-tasks?timeout=60');

        // THEN: Should use custom timeout
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(1, $data['stuck_tasks']);
    }

    public function testEndpointsRequireAuthentication(): void
    {
        // GIVEN: Unauthenticated client
        $client = static::createClient();

        // WHEN: Attempting to access monitoring endpoints without auth
        $client->request('GET', '/api/monitoring/queue/statistics');

        // THEN: Should return 401 Unauthorized
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }
}
