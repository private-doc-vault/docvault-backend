<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    public function testUserCanBeInstantiated(): void
    {
        $this->assertInstanceOf(User::class, $this->user);
    }

    public function testUserImplementsUserInterface(): void
    {
        $this->assertInstanceOf(UserInterface::class, $this->user);
    }

    public function testUserImplementsPasswordAuthenticatedUserInterface(): void
    {
        $this->assertInstanceOf(PasswordAuthenticatedUserInterface::class, $this->user);
    }

    public function testUserHasUuidId(): void
    {
        // Test that ID is auto-generated as UUID
        $this->assertNotNull($this->user->getId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->user->getId());

        // Test that ID can be set (for testing purposes)
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->user->setId($uuid);
        $this->assertEquals($uuid, $this->user->getId());
    }

    public function testUserEmail(): void
    {
        $email = 'user@example.com';
        $this->user->setEmail($email);
        
        $this->assertEquals($email, $this->user->getEmail());
    }

    public function testUserEmailIsUserIdentifier(): void
    {
        $email = 'user@example.com';
        $this->user->setEmail($email);
        
        $this->assertEquals($email, $this->user->getUserIdentifier());
    }

    public function testUserPassword(): void
    {
        $password = 'hashed-password-string';
        $this->user->setPassword($password);
        
        $this->assertEquals($password, $this->user->getPassword());
    }

    public function testUserFirstName(): void
    {
        $firstName = 'John';
        $this->user->setFirstName($firstName);
        
        $this->assertEquals($firstName, $this->user->getFirstName());
    }

    public function testUserLastName(): void
    {
        $lastName = 'Doe';
        $this->user->setLastName($lastName);
        
        $this->assertEquals($lastName, $this->user->getLastName());
    }

    public function testUserFullName(): void
    {
        $this->user->setFirstName('John');
        $this->user->setLastName('Doe');
        
        $this->assertEquals('John Doe', $this->user->getFullName());
    }

    public function testUserFullNameWithOnlyFirstName(): void
    {
        $this->user->setFirstName('John');
        
        $this->assertEquals('John', $this->user->getFullName());
    }

    public function testUserFullNameWithOnlyLastName(): void
    {
        $this->user->setLastName('Doe');
        
        $this->assertEquals('Doe', $this->user->getFullName());
    }

    public function testUserFullNameWhenEmpty(): void
    {
        $this->assertEquals('', $this->user->getFullName());
    }

    public function testUserIsActive(): void
    {
        // Default should be active
        $this->assertTrue($this->user->isActive());
        
        $this->user->setIsActive(false);
        $this->assertFalse($this->user->isActive());
        
        $this->user->setIsActive(true);
        $this->assertTrue($this->user->isActive());
    }

    public function testUserIsVerified(): void
    {
        // Default should be unverified
        $this->assertFalse($this->user->isVerified());
        
        $this->user->setIsVerified(true);
        $this->assertTrue($this->user->isVerified());
        
        $this->user->setIsVerified(false);
        $this->assertFalse($this->user->isVerified());
    }

    public function testUserRoles(): void
    {
        // Default should include ROLE_USER
        $this->assertEquals(['ROLE_USER'], $this->user->getRoles());
        
        $roles = ['ROLE_USER', 'ROLE_ADMIN'];
        $this->user->setRoles($roles);
        $this->assertEquals($roles, $this->user->getRoles());
    }

    public function testUserRolesAlwaysIncludesRoleUser(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $roles = $this->user->getRoles();
        
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testUserRolesAreUnique(): void
    {
        $this->user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);
        $roles = $this->user->getRoles();
        
        $this->assertCount(2, $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    public function testUserLastLoginAt(): void
    {
        $this->assertNull($this->user->getLastLoginAt());
        
        $lastLogin = new \DateTimeImmutable();
        $this->user->setLastLoginAt($lastLogin);
        
        $this->assertEquals($lastLogin, $this->user->getLastLoginAt());
    }

    public function testUserEmailVerifiedAt(): void
    {
        $this->assertNull($this->user->getEmailVerifiedAt());
        
        $verifiedAt = new \DateTimeImmutable();
        $this->user->setEmailVerifiedAt($verifiedAt);
        
        $this->assertEquals($verifiedAt, $this->user->getEmailVerifiedAt());
    }

    public function testUserCreatedAt(): void
    {
        $this->assertNull($this->user->getCreatedAt());
        
        $createdAt = new \DateTimeImmutable();
        $this->user->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->user->getCreatedAt());
    }

    public function testUserUpdatedAt(): void
    {
        $this->assertNull($this->user->getUpdatedAt());
        
        $updatedAt = new \DateTimeImmutable();
        $this->user->setUpdatedAt($updatedAt);
        
        $this->assertEquals($updatedAt, $this->user->getUpdatedAt());
    }

    public function testUserPreferences(): void
    {
        // Default should be empty array
        $this->assertEquals([], $this->user->getPreferences());
        
        $preferences = [
            'theme' => 'dark',
            'language' => 'en',
            'notifications' => true
        ];
        
        $this->user->setPreferences($preferences);
        $this->assertEquals($preferences, $this->user->getPreferences());
    }

    public function testUserPreference(): void
    {
        $preferences = [
            'theme' => 'dark',
            'language' => 'en'
        ];
        
        $this->user->setPreferences($preferences);
        
        $this->assertEquals('dark', $this->user->getPreference('theme'));
        $this->assertEquals('en', $this->user->getPreference('language'));
        $this->assertNull($this->user->getPreference('nonexistent'));
    }

    public function testUserSetPreference(): void
    {
        $this->user->setPreference('theme', 'dark');
        $this->user->setPreference('language', 'en');
        
        $this->assertEquals('dark', $this->user->getPreference('theme'));
        $this->assertEquals('en', $this->user->getPreference('language'));
        
        $expected = ['theme' => 'dark', 'language' => 'en'];
        $this->assertEquals($expected, $this->user->getPreferences());
    }

    public function testUserDocuments(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->user->getDocuments());
        $this->assertCount(0, $this->user->getDocuments());
    }

    public function testUserGroups(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->user->getGroups());
        $this->assertCount(0, $this->user->getGroups());
    }

    public function testUserAuditLogs(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->user->getAuditLogs());
        $this->assertCount(0, $this->user->getAuditLogs());
    }

    public function testUserHasRole(): void
    {
        $this->assertTrue($this->user->hasRole('ROLE_USER'));
        $this->assertFalse($this->user->hasRole('ROLE_ADMIN'));
        
        $this->user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->assertTrue($this->user->hasRole('ROLE_ADMIN'));
    }

    public function testUserAddRole(): void
    {
        $this->user->addRole('ROLE_ADMIN');
        
        $this->assertTrue($this->user->hasRole('ROLE_ADMIN'));
        $this->assertTrue($this->user->hasRole('ROLE_USER'));
    }

    public function testUserRemoveRole(): void
    {
        $this->user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $this->user->removeRole('ROLE_ADMIN');
        
        $this->assertFalse($this->user->hasRole('ROLE_ADMIN'));
        $this->assertTrue($this->user->hasRole('ROLE_USER'));
    }

    public function testUserCannotRemoveRoleUser(): void
    {
        $this->user->removeRole('ROLE_USER');
        
        // ROLE_USER should still be present
        $this->assertTrue($this->user->hasRole('ROLE_USER'));
    }

    public function testUserIsAdmin(): void
    {
        $this->assertFalse($this->user->isAdmin());

        $this->user->addRole('ROLE_ADMIN');
        $this->assertTrue($this->user->isAdmin());
    }

    public function testUserToString(): void
    {
        $this->user->setEmail('user@example.com');
        $this->user->setFirstName('John');
        $this->user->setLastName('Doe');
        
        $this->assertEquals('John Doe (user@example.com)', (string) $this->user);
    }

    public function testUserToStringWithOnlyEmail(): void
    {
        $this->user->setEmail('user@example.com');
        
        $this->assertEquals('user@example.com', (string) $this->user);
    }
}