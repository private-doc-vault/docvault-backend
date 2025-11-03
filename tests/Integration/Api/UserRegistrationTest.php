<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for user registration endpoint
 *
 * Following TDD methodology - RED phase (tests written first)
 * Tests API contract for user registration functionality
 */
class UserRegistrationTest extends WebTestCase
{
    public function testRegisterWithValidDataCreatesUserAndReturnsSuccess(): void
    {
        // Arrange
        $client = static::createClient();

        $userData = [
            'email' => 'newuser' . time() . '@example.com', // Make email unique
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $responseData);
        $this->assertArrayHasKey('email', $responseData);
        $this->assertArrayHasKey('firstName', $responseData);
        $this->assertArrayHasKey('lastName', $responseData);
        $this->assertArrayHasKey('isActive', $responseData);
        $this->assertArrayHasKey('isVerified', $responseData);
        $this->assertArrayHasKey('createdAt', $responseData);

        // Verify sensitive data is not exposed
        $this->assertArrayNotHasKey('password', $responseData);

        // Verify correct values
        $this->assertEquals($userData['email'], $responseData['email']);
        $this->assertEquals($userData['firstName'], $responseData['firstName']);
        $this->assertEquals($userData['lastName'], $responseData['lastName']);
        $this->assertTrue($responseData['isActive']);
        $this->assertFalse($responseData['isVerified']); // New users start unverified
    }

    public function testRegisterWithMissingEmailReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        $userData = [
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertStringContainsString('email', strtolower($responseData['error']));
    }

    public function testRegisterWithMissingPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        $userData = [
            'email' => 'newuser@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertStringContainsString('password', strtolower($responseData['error']));
    }

    public function testRegisterWithInvalidEmailFormatReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        $userData = [
            'email' => 'invalid-email-format',
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertStringContainsString('email', strtolower($responseData['error']));
    }

    public function testRegisterWithWeakPasswordReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        $userData = [
            'email' => 'newuser@example.com',
            'password' => '123', // Too weak
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
        $this->assertStringContainsString('password', strtolower($responseData['error']));
    }

    public function testRegisterWithDuplicateEmailReturnsConflict(): void
    {
        // Arrange
        $client = static::createClient();

        $email = 'duplicate' . time() . '@example.com';
        $userData = [
            'email' => $email,
            'password' => 'SecurePass123!',
            'firstName' => 'John',
            'lastName' => 'Doe'
        ];

        // First registration - should succeed
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );
        $this->assertEquals(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());

        // Act - Second registration with same email
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Assert
        $this->assertEquals(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('already exists', strtolower($responseData['error']));
    }

    public function testRegisterWithEmptyJsonReturnsUnprocessableEntity(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}'
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertArrayHasKey('violations', $responseData);
    }

    public function testRegisterWithInvalidJsonReturnsBadRequest(): void
    {
        // Arrange
        $client = static::createClient();

        // Act
        $client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid-json-data'
        );

        // Assert
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('invalid json', strtolower($responseData['error']));
    }
}