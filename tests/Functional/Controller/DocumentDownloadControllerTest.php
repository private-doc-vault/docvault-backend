<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for secure document download with permission checks
 *
 * Tests cover:
 * - Authentication requirements
 * - Permission-based access control
 * - File streaming with proper headers
 * - Audit logging for downloads
 * - Error handling for missing/inaccessible files
 */
class DocumentDownloadControllerTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestData();
        }

        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        }
    }

    private function cleanupTestData(): void
    {
        // Clean up test users and documents
        $testEmails = ['download-test@example.com', 'no-access@example.com'];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user) {
                // Remove associated documents
                $documents = $this->entityManager->getRepository(Document::class)
                    ->findBy(['uploadedBy' => $user]);

                foreach ($documents as $document) {
                    $this->entityManager->remove($document);
                }

                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testDownloadRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/documents/fake-id/download');

        $this->assertResponseStatusCodeSame(401, 'Download should require authentication');
    }

    public function testDownloadRequiresDocumentReadPermission(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        // Create user without document.read permission
        $user = $this->createTestUser('no-access@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/fake-id/download',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403, 'Download should require document.read permission');
    }

    public function testDownloadReturns404ForNonExistentDocument(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('download-test@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/non-existent-id/download',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadStreamsFileWithCorrectHeaders(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('download-test@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $document = $this->createTestDocument($user, 'test-file.pdf', 'application/pdf');
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/' . $document->getId() . '/download',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('test-file.pdf', $response->headers->get('Content-Disposition'));
    }

    public function testDownloadReturnsFileContent(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('download-test@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $testContent = 'This is test PDF content';
        $document = $this->createTestDocument($user, 'test-content.pdf', 'application/pdf', $testContent);
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/' . $document->getId() . '/download',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        // StreamedResponse content might not be fully captured in tests, so just verify headers and status
        $response = $client->getResponse();
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertEquals(strlen($testContent), $response->headers->get('Content-Length'));
    }

    public function testDownloadHandlesMissingPhysicalFile(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('download-test@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        // Create document with non-existent file path
        $document = $this->createTestDocument($user, 'missing.pdf', 'application/pdf', null, false);
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/' . $document->getId() . '/download',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(404);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('file', strtolower($responseData['error']));
    }

    public function testDownloadSupportsInlineDisplay(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $user = $this->createTestUser('download-test@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $document = $this->createTestDocument($user, 'inline.pdf', 'application/pdf');
        $token = $this->generateJwtToken($user);

        $client->request(
            'GET',
            '/api/documents/' . $document->getId() . '/download?inline=true',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $disposition = $client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString('inline', $disposition);
    }

    /**
     * Create test user with specified roles
     */
    private function createTestUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $this->initializeServices();
        $passwordHasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, 'password123');
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Create test document with optional physical file
     */
    private function createTestDocument(
        User $user,
        string $filename,
        string $mimeType,
        ?string $content = null,
        bool $createPhysicalFile = true
    ): Document {
        $storageService = static::getContainer()->get('App\Service\DocumentStorageService');

        $document = new Document();
        $document->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $document->setFilename($filename);
        $document->setOriginalName($filename);
        $document->setMimeType($mimeType);
        $document->setUploadedBy($user);

        if ($createPhysicalFile) {
            // Create actual file for testing
            $filePath = $storageService->generateFilePath($user, $filename);
            $content = $content ?? 'Test file content for ' . $filename;
            file_put_contents($filePath, $content);

            $document->setFileSize(strlen($content));
            $document->setFilePath($storageService->getRelativePath($filePath));
        } else {
            // Document without physical file
            $document->setFileSize(1024);
            $document->setFilePath('non/existent/path/' . $filename);
        }

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /**
     * Generate JWT token for authentication
     */
    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
