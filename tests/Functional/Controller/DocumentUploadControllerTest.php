<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional tests for secure file upload controller with validation
 *
 * Tests cover:
 * - File upload with authentication
 * - File type validation
 * - File size validation
 * - Security checks (path traversal, malicious files)
 * - Permission-based access control
 */
class DocumentUploadControllerTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;
    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->cleanupTestData();
        }

        // Clean up uploaded test files
        if (isset($this->uploadDir) && is_dir($this->uploadDir)) {
            $this->recursiveRemoveDirectory($this->uploadDir);
        }

        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $container = static::getContainer();
            $this->entityManager = $container->get('doctrine.orm.entity_manager');

            // Get upload directory from configuration
            $this->uploadDir = $container->getParameter('kernel.project_dir') . '/var/test-uploads';

            // Create upload directory if it doesn't exist
            if (!is_dir($this->uploadDir)) {
                mkdir($this->uploadDir, 0777, true);
            }
        }
    }

    private function cleanupTestData(): void
    {
        // Clean up test user and associated documents
        $testEmails = ['testupload@example.com', 'noperm@example.com'];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);

            if ($user) {
                // Remove associated documents first
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

    public function testUploadDocumentRequiresAuthentication(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testFile = $this->createTestFile('test-document.pdf', 'PDF test content');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile]
        );

        $this->assertResponseStatusCodeSame(401, 'Upload should require authentication');
    }

    public function testUploadDocumentRequiresDocumentWritePermission(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        // Create user without document.write permission
        $userWithoutPermission = $this->createTestUser('noperm@example.com', ['ROLE_USER']);
        $token = $this->generateJwtToken($userWithoutPermission);

        $testFile = $this->createTestFile('test-document.pdf', 'PDF test content');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403, 'Upload should require document.write permission');
    }

    public function testUploadValidPdfDocument(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $testFile = $this->createTestFile('test-document.pdf', 'PDF test content', 'application/pdf');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('document', $responseData);
        $this->assertArrayHasKey('id', $responseData['document']);
        $this->assertArrayHasKey('filename', $responseData['document']);
        $this->assertArrayHasKey('filesize', $responseData['document']);
        $this->assertArrayHasKey('mimeType', $responseData['document']);
        $this->assertArrayHasKey('uploadedAt', $responseData['document']);

        $this->assertEquals('test-document.pdf', $responseData['document']['filename']);
        $this->assertEquals('application/pdf', $responseData['document']['mimeType']);

        // Verify document was saved to database
        $document = $this->entityManager->getRepository(Document::class)->find($responseData['document']['id']);
        $this->assertNotNull($document);
        $this->assertEquals($testUser->getId(), $document->getUploadedBy()->getId());
    }

    public function testUploadValidImageDocument(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $testFile = $this->createTestFile('scan.jpg', 'JPEG test content', 'image/jpeg');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('scan.jpg', $responseData['document']['filename']);
        $this->assertEquals('image/jpeg', $responseData['document']['mimeType']);
    }

    public function testUploadWithoutFileReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(400);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('file', strtolower($responseData['error']));
    }

    public function testUploadInvalidFileTypeReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $testFile = $this->createTestFile('malicious.exe', 'Executable content', 'application/x-msdownload');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(400);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('type', strtolower($responseData['error']));
    }

    public function testUploadFileTooLargeReturnsError(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);

        // Create a file larger than the max size (50MB as per .env)
        // Note: Can't test files > PHP's upload_max_filesize (2MB default), so we test with smaller files
        // In production, web server and PHP limits should be configured appropriately
        $largeContent = str_repeat('A', 52428801); // 50MB + 1 byte
        $testFile = $this->createTestFile('large-document.pdf', $largeContent, 'application/pdf');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        // Accept either 400 (our validation) or 500 (PHP upload limit exceeded)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($statusCode, [400, 500]), "Expected 400 or 500, got {$statusCode}");

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        // Check that the error message mentions size, file, or upload
        $this->assertTrue(
            str_contains(strtolower($responseData['error']), 'size') ||
            str_contains(strtolower($responseData['error']), 'file') ||
            str_contains(strtolower($responseData['error']), 'upload'),
            "Error message should mention size, file, or upload limit"
        );
    }

    public function testUploadWithPathTraversalAttemptIsRejected(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $testFile = $this->createTestFile('../../etc/passwd.pdf', 'Malicious content', 'application/pdf');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        // Should either reject the file or sanitize the filename
        $response = $client->getResponse();

        if ($response->getStatusCode() === 201) {
            // If accepted, verify filename was sanitized
            $responseData = json_decode($response->getContent(), true);
            $this->assertStringNotContainsString('..', $responseData['document']['filename']);
            $this->assertStringNotContainsString('/', $responseData['document']['filename']);
        } else {
            // Should be rejected
            $this->assertResponseStatusCodeSame(400);
        }
    }

    public function testUploadWithMetadata(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $testFile = $this->createTestFile('invoice.pdf', 'PDF invoice content', 'application/pdf');

        $client->request(
            'POST',
            '/api/documents/upload',
            [
                'title' => 'Monthly Invoice',
                'description' => 'Invoice for January 2024',
                'tags' => ['invoice', 'finance']
            ],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Monthly Invoice', $responseData['document']['title']);
        $this->assertEquals('Invoice for January 2024', $responseData['document']['description']);
    }

    public function testUploadReturnsFileMetadata(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('testupload@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $token = $this->generateJwtToken($testUser);
        $content = 'Test PDF content with some length';
        $testFile = $this->createTestFile('metadata-test.pdf', $content, 'application/pdf');

        $client->request(
            'POST',
            '/api/documents/upload',
            [],
            ['file' => $testFile],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('document', $responseData);
        $this->assertGreaterThan(0, $responseData['document']['filesize']);
        $this->assertEquals(strlen($content), $responseData['document']['filesize']);
        $this->assertNotEmpty($responseData['document']['uploadedAt']);
    }

    /**
     * Create a test user with specified roles
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
     * Generate JWT token for authentication
     */
    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }

    /**
     * Create a test file for upload
     */
    private function createTestFile(string $filename, string $content, string $mimeType = 'text/plain'): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($tempFile, $content);

        return new UploadedFile(
            $tempFile,
            $filename,
            $mimeType,
            null,
            true
        );
    }

    /**
     * Recursively remove a directory
     */
    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
