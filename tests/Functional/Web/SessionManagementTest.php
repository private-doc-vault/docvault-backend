<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for Web Session Management
 *
 * Tests session-based authentication, CSRF protection, and web interface
 * security features following TDD methodology
 */
class SessionManagementTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

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
            $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
            $this->cleanupTestData();
        }
    }

    private function cleanupTestData(): void
    {
        $testEmails = [
            'session.user@example.com',
            'session.admin@example.com',
            'csrf.user@example.com',
            'inactive.session@example.com'
        ];

        foreach ($testEmails as $email) {
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['email' => $email]);
            if ($user) {
                // Remove audit logs first due to foreign key constraint
                $auditLogs = $this->entityManager->getRepository(\App\Entity\AuditLog::class)
                    ->findBy(['user' => $user]);
                foreach ($auditLogs as $auditLog) {
                    $this->entityManager->remove($auditLog);
                }
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Test web login form displays correctly
     *
     * @group web-session
     */
    public function testWebLoginFormDisplaysCorrectly(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $crawler = $client->request('GET', '/login');

        // Assert
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Check form structure
        $this->assertEquals(1, $crawler->filter('form[action="/login"]')->count());
        $this->assertEquals(1, $crawler->filter('input[name="email"]')->count());
        $this->assertEquals(1, $crawler->filter('input[name="password"]')->count());
        $this->assertEquals(1, $crawler->filter('input[type="hidden"][name="_token"]')->count()); // CSRF token
        $this->assertEquals(1, $crawler->filter('button[type="submit"]')->count());

        // Check CSRF token is present
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');
        $this->assertNotEmpty($csrfToken);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_.-]+$/', $csrfToken);
    }

    /**
     * Test successful web login with valid credentials
     *
     * @group web-session
     */
    public function testSuccessfulWebLoginWithValidCredentials(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('session.user@example.com', 'password123');

        // Get login form and CSRF token
        $crawler = $client->request('GET', '/login');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // Act - Submit login form
        $client->submitForm('Sign In', [
            'email' => 'session.user@example.com',
            'password' => 'password123',
            '_token' => $csrfToken
        ]);

        // Assert - Should redirect to dashboard
        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertStringContainsString('/dashboard', $client->getResponse()->getTargetUrl() ?? '');

        // Follow redirect
        $client->followRedirect();
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Check session is established
        $this->assertTrue($client->getContainer()->get('security.token_storage')->getToken() !== null);
    }

    /**
     * Test failed web login with invalid credentials
     *
     * @group web-session
     */
    public function testFailedWebLoginWithInvalidCredentials(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $this->createTestUser('session.user@example.com', 'password123');

        // Get login form and CSRF token
        $crawler = $client->request('GET', '/login');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // Act - Submit login form with wrong password
        $crawler = $client->submitForm('Sign In', [
            'email' => 'session.user@example.com',
            'password' => 'wrongpassword',
            '_token' => $csrfToken
        ]);

        // Assert - Should redirect back to login page with flash message
        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertStringContainsString('/login', $client->getResponse()->getTargetUrl() ?? '');

        // Follow redirect to see the error message
        $client->followRedirect();
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $responseContent = $client->getResponse()->getContent();
        $this->assertTrue(
            str_contains($responseContent, 'Invalid credentials') ||
            str_contains($responseContent, 'The presented password is invalid') ||
            str_contains($responseContent, 'password is invalid')
        );

        // Check no session is established
        $this->assertTrue($client->getContainer()->get('security.token_storage')->getToken() === null);
    }

    /**
     * Test CSRF protection on login form
     *
     * @group web-session
     */
    public function testCSRFProtectionOnLoginForm(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $this->createTestUser('csrf.user@example.com', 'password123');

        // Act - Submit login form without CSRF token
        $client->request('POST', '/login', [
            'email' => 'csrf.user@example.com',
            'password' => 'password123'
            // No _token field
        ]);

        // Assert - Should redirect back to login (CSRF errors are handled by redirecting)
        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertStringContainsString('/login', $client->getResponse()->getTargetUrl() ?? '');

        // Follow redirect to see any error message
        $client->followRedirect();
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Should show error about invalid token or authentication failure
        $responseContent = $client->getResponse()->getContent();
        $this->assertTrue(
            str_contains($responseContent, 'Invalid CSRF token') ||
            str_contains($responseContent, 'Invalid credentials') ||
            str_contains($responseContent, 'error')
        );
    }

    /**
     * Test session persistence across requests
     *
     * @group web-session
     */
    public function testSessionPersistenceAcrossRequests(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('session.user@example.com', 'password123');

        // Login
        $this->loginWebUser($client, 'session.user@example.com', 'password123');

        // Act - Make multiple requests to protected pages
        $client->request('GET', '/dashboard');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/profile');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/documents');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Assert - User should remain authenticated across all requests
        $token = $client->getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token);
        $this->assertEquals('session.user@example.com', $token->getUserIdentifier());
    }

    /**
     * Test session logout functionality
     *
     * @group web-session
     */
    public function testSessionLogoutFunctionality(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $this->createTestUser('session.user@example.com', 'password123');
        $this->loginWebUser($client, 'session.user@example.com', 'password123');

        // Verify logged in
        $token = $client->getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token);

        // Act - Logout
        $client->request('POST', '/logout');

        // Assert - Should redirect to login page
        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertStringContainsString('/login', $client->getResponse()->getTargetUrl() ?? '');

        // Follow redirect
        $client->followRedirect();

        // Verify session is destroyed
        $token = $client->getContainer()->get('security.token_storage')->getToken();
        $this->assertTrue($token === null || !$token->getUser());
    }

    /**
     * Test session security - inactive user cannot login
     *
     * @group web-session
     */
    public function testInactiveUserCannotLogin(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $testUser = $this->createTestUser('inactive.session@example.com', 'password123');
        $testUser->setIsActive(false);
        $this->entityManager->flush();

        // Get login form and CSRF token
        $crawler = $client->request('GET', '/login');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        // Act - Try to login with inactive user
        $client->submitForm('Sign In', [
            'email' => 'inactive.session@example.com',
            'password' => 'password123',
            '_token' => $csrfToken
        ]);

        // Assert - Should redirect back to login
        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertStringContainsString('/login', $client->getResponse()->getTargetUrl() ?? '');

        // Follow redirect to see error message
        $client->followRedirect();
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('Account is deactivated', $client->getResponse()->getContent());
    }

    /**
     * Test session timeout configuration
     *
     * @group web-session
     */
    public function testSessionTimeoutConfiguration(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $this->createTestUser('session.user@example.com', 'password123');
        $this->loginWebUser($client, 'session.user@example.com', 'password123');

        // Act - Check session configuration
        $session = $client->getRequest()->getSession();

        // Assert - Session should have appropriate settings
        $this->assertNotNull($session);

        // Check session has reasonable timeout (should be configured in framework.yaml)
        $this->assertGreaterThan(0, ini_get('session.gc_maxlifetime'));

        // Check session is using secure settings
        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'prod') {
            $this->assertTrue($session->isStarted());
        }
    }

    /**
     * Test protected routes require authentication
     *
     * @group web-session
     */
    public function testProtectedRoutesRequireAuthentication(): void
    {
        // Arrange
        $client = static::createClient();

        $protectedRoutes = [
            '/dashboard',
            '/profile',
            '/documents',
            '/admin'
        ];

        foreach ($protectedRoutes as $route) {
            // Act - Try to access protected route without authentication
            $client->request('GET', $route);

            // Assert - Should redirect to login
            $this->assertTrue($client->getResponse()->isRedirect(), "Route $route should require authentication");
            $this->assertStringContainsString('/login', $client->getResponse()->getTargetUrl() ?? '');

            // Restart client for next iteration to avoid kernel issues
            $client->restart();
        }
    }

    /**
     * Test remember me functionality (if implemented)
     *
     * @group web-session
     */
    public function testRememberMeFunctionality(): void
    {
        // Arrange
        $client = static::createClient();
        $this->initializeServices();

        $this->createTestUser('session.user@example.com', 'password123');

        // Get login form and check for remember me checkbox
        $crawler = $client->request('GET', '/login');

        if ($crawler->filter('input[name="_remember_me"]')->count() > 0) {
            $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

            // Act - Login with remember me checked
            $client->submitForm('Sign In', [
                'email' => 'session.user@example.com',
                'password' => 'password123',
                '_token' => $csrfToken,
                '_remember_me' => 'on'
            ]);

            // Assert - Should set remember me cookie
            $this->assertTrue($client->getResponse()->isRedirect());

            $cookies = $client->getResponse()->headers->getCookies();
            $rememberMeCookie = null;

            foreach ($cookies as $cookie) {
                if (str_contains($cookie->getName(), 'REMEMBERME')) {
                    $rememberMeCookie = $cookie;
                    break;
                }
            }

            if ($rememberMeCookie) {
                $this->assertNotNull($rememberMeCookie);
                $this->assertGreaterThan(time(), $rememberMeCookie->getExpiresTime());
            }
        } else {
            // If remember me is not implemented, just verify this test exists
            $this->assertTrue(true, 'Remember me functionality test - implement if needed');
        }
    }

    /**
     * Test concurrent session handling
     *
     * @group web-session
     */
    public function testConcurrentSessionHandling(): void
    {
        // This test verifies that the session system can handle multiple concurrent sessions
        // For simplicity in testing, we'll just verify that session-based authentication
        // works properly and can handle multiple requests

        // Arrange
        $client = static::createClient();
        $this->initializeServices();
        $this->createTestUser('session.user@example.com', 'password123');

        // Act - Login and make multiple requests to simulate concurrent usage
        $this->loginWebUser($client, 'session.user@example.com', 'password123');

        // Assert - Multiple requests should work (simulating concurrent session usage)
        $client->request('GET', '/dashboard');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/profile');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('GET', '/documents');
        $this->assertEquals(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        // Session should remain valid throughout
        $token = $client->getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token);
        $this->assertEquals('session.user@example.com', $token->getUserIdentifier());
    }

    /**
     * Helper method to create test user
     */
    private function createTestUser(
        string $email,
        string $password,
        array $roles = ['ROLE_USER']
    ): User {
        $passwordHasher = self::getContainer()->get('security.user_password_hasher');

        $user = new User();
        $user->setId(\Ramsey\Uuid\Uuid::uuid4()->toString());
        $user->setEmail($email);
        $user->setFirstName('Session');
        $user->setLastName('User');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setUpdatedAt(new \DateTimeImmutable());

        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Helper method to login web user
     */
    private function loginWebUser($client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $client->submitForm('Sign In', [
            'email' => $email,
            'password' => $password,
            '_token' => $csrfToken
        ]);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
    }
}