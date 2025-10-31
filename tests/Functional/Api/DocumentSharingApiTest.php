<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Document;
use App\Entity\DocumentShare;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Document Sharing API Tests
 *
 * Tests cover:
 * - Sharing documents with users
 * - Listing shared documents
 * - Updating share permissions
 * - Revoking shares
 * - Access control and permissions
 */
class DocumentSharingApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();

        parent::tearDown();
    }

    private function initializeServices(): void
    {
        if (!isset($this->entityManager)) {
            $container = static::getContainer();
            $this->entityManager = $container->get('doctrine.orm.entity_manager');
        }
    }

    private function cleanupTestData(): void
    {
        try {
            $container = static::getContainer();
            $em = $container->get('doctrine.orm.entity_manager');

            $testEmails = [
                'docshare.owner@example.com',
                'docshare.recipient@example.com',
                'docshare.other@example.com'
            ];

            foreach ($testEmails as $email) {
                $user = $em->getRepository(User::class)
                    ->findOneBy(['email' => $email]);

                if ($user) {
                    $em->remove($user);
                }
            }

            $em->flush();
            $em->clear();
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }

    // ========== Share Document Tests ==========

    public function testShareDocumentRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/documents/test-doc-id/share');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testShareDocumentWithUser(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        $token = $this->generateJwtToken($owner);

        $client->request(
            'POST',
            '/api/documents/' . $document->getId() . '/share',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userId' => $recipient->getId(),
                'permissionLevel' => 'view',
                'note' => 'Please review this document'
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('sharedWith', $responseData);
        $this->assertEquals($recipient->getId(), $responseData['sharedWith']['id']);
        $this->assertEquals('view', $responseData['permissionLevel']);
        $this->assertEquals('Please review this document', $responseData['note']);
    }

    public function testShareDocumentWithWritePermission(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        $token = $this->generateJwtToken($owner);

        $client->request(
            'POST',
            '/api/documents/' . $document->getId() . '/share',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userId' => $recipient->getId(),
                'permissionLevel' => 'write'
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals('write', $responseData['permissionLevel']);
    }

    public function testShareDocumentWithExpiration(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        $token = $this->generateJwtToken($owner);
        $expiresAt = (new \DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i:s\Z');

        $client->request(
            'POST',
            '/api/documents/' . $document->getId() . '/share',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userId' => $recipient->getId(),
                'permissionLevel' => 'view',
                'expiresAt' => $expiresAt
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('expiresAt', $responseData);
        $this->assertNotNull($responseData['expiresAt']);
    }

    public function testCannotShareDocumentYouDontOwn(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $otherUser = $this->createTestUser('docshare.other@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        $token = $this->generateJwtToken($otherUser);

        $client->request(
            'POST',
            '/api/documents/' . $document->getId() . '/share',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'userId' => $recipient->getId(),
                'permissionLevel' => 'view'
            ])
        );

        $this->assertResponseStatusCodeSame(403);
    }

    // ========== List Shares Tests ==========

    public function testListDocumentShares(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        // Create a share
        $share = $this->createTestShare($document, $owner, $recipient, 'view');

        $token = $this->generateJwtToken($owner);

        $client->request(
            'GET',
            '/api/documents/' . $document->getId() . '/shares',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('shares', $responseData);
        $this->assertCount(1, $responseData['shares']);
        $this->assertEquals($recipient->getId(), $responseData['shares'][0]['sharedWith']['id']);
    }

    public function testListSharedWithMe(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);

        // Create a share
        $this->createTestShare($document, $owner, $recipient, 'view');

        $token = $this->generateJwtToken($recipient);

        $client->request(
            'GET',
            '/api/shares/shared-with-me',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('documents', $responseData);
        $this->assertGreaterThanOrEqual(1, count($responseData['documents']));
    }

    // ========== Update Share Tests ==========

    public function testUpdateSharePermission(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);
        $share = $this->createTestShare($document, $owner, $recipient, 'view');

        $token = $this->generateJwtToken($owner);

        $client->request(
            'PUT',
            '/api/shares/' . $share->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode([
                'permissionLevel' => 'write'
            ])
        );

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertEquals('write', $responseData['permissionLevel']);
    }

    // ========== Revoke Share Tests ==========

    public function testRevokeShare(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $document = $this->createTestDocument($owner);
        $share = $this->createTestShare($document, $owner, $recipient, 'view');

        $token = $this->generateJwtToken($owner);

        $client->request(
            'DELETE',
            '/api/shares/' . $share->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [200, 204]),
            'Revoke should return 200 or 204'
        );

        // Verify share is deleted
        $deletedShare = $this->entityManager->getRepository(DocumentShare::class)
            ->find($share->getId());

        $this->assertNull($deletedShare);
    }

    public function testCannotRevokeShareYouDontOwn(): void
    {
        $client = static::createClient();
        $this->initializeServices();

        $owner = $this->createTestUser('docshare.owner@example.com');
        $recipient = $this->createTestUser('docshare.recipient@example.com');
        $otherUser = $this->createTestUser('docshare.other@example.com');
        $document = $this->createTestDocument($owner);
        $share = $this->createTestShare($document, $owner, $recipient, 'view');

        $token = $this->generateJwtToken($otherUser);

        $client->request(
            'DELETE',
            '/api/shares/' . $share->getId(),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    // ========== Helper Methods ==========

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

    private function createTestDocument(User $owner): Document
    {
        $document = new Document();
        $document->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $document->setFilename('test-document.pdf');
        $document->setOriginalName('Test Document.pdf');
        $document->setFilePath('/storage/test-document.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(1024);
        $document->setUploadedBy($owner);
        $document->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    private function createTestShare(
        Document $document,
        User $sharedBy,
        User $sharedWith,
        string $permissionLevel
    ): DocumentShare {
        $share = new DocumentShare();
        $share->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $share->setDocument($document);
        $share->setSharedBy($sharedBy);
        $share->setSharedWith($sharedWith);
        $share->setPermissionLevel($permissionLevel);
        $share->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($share);
        $this->entityManager->flush();

        return $share;
    }

    private function generateJwtToken(User $user): string
    {
        $jwtManager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        return $jwtManager->create($user);
    }
}
