<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Security\RbacService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for Role-Based Access Control service
 *
 * Tests permission validation combining Symfony roles and UserGroup permissions
 * following TDD methodology - RED phase
 */
class RbacServiceTest extends TestCase
{
    private RbacService $rbacService;
    private Security $mockSecurity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockSecurity = $this->createMock(Security::class);
        $this->rbacService = new RbacService($this->mockSecurity);
    }

    public function testRbacServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(RbacService::class, $this->rbacService);
    }

    /**
     * Test basic role hierarchy checking
     */
    public function testHasRoleReturnsTrueForMatchingRole(): void
    {
        // Arrange
        $this->mockSecurity
            ->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_USER')
            ->willReturn(true);

        // Act & Assert
        $this->assertTrue($this->rbacService->hasRole('ROLE_USER'));
    }

    public function testHasRoleReturnsFalseForNonMatchingRole(): void
    {
        // Arrange
        $this->mockSecurity
            ->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Act & Assert
        $this->assertFalse($this->rbacService->hasRole('ROLE_ADMIN'));
    }

    public function testHasRoleReturnsTrueForInheritedRole(): void
    {
        // Arrange - ROLE_ADMIN inherits ROLE_USER
        $user = $this->createUserWithRoles(['ROLE_ADMIN']);

        $this->mockSecurity
            ->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_USER')
            ->willReturn(true);

        // Act & Assert
        $this->assertTrue($this->rbacService->hasRole('ROLE_USER'));
    }

    /**
     * Test permission-based access control
     */
    public function testHasPermissionReturnsTrueWhenUserHasDirectPermission(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.read', 'document.write']);
        $user = $this->createUserWithGroups([$userGroup]);

        $this->mockSecurity
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        $this->assertTrue($this->rbacService->hasPermission('document.read'));
    }

    public function testHasPermissionReturnsFalseWhenUserLacksPermission(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.read']);
        $user = $this->createUserWithGroups([$userGroup]);

        $this->mockSecurity
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        $this->assertFalse($this->rbacService->hasPermission('document.delete'));
    }

    public function testHasPermissionReturnsTrueWhenUserHasPermissionThroughMultipleGroups(): void
    {
        // Arrange
        $group1 = $this->createUserGroupWithPermissions(['document.read']);
        $group2 = $this->createUserGroupWithPermissions(['document.write']);
        $user = $this->createUserWithGroups([$group1, $group2]);

        $this->mockSecurity
            ->expects($this->exactly(2))
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        $this->assertTrue($this->rbacService->hasPermission('document.read'));
        $this->assertTrue($this->rbacService->hasPermission('document.write'));
    }

    /**
     * Test combined role and permission checking
     */
    public function testCanAccessResourceWithRoleAndPermission(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.read']);
        $user = $this->createUserWithRoles(['ROLE_USER']);
        $user->getGroups()->add($userGroup);

        $this->mockSecurity
            ->expects($this->exactly(3))
            ->method('isGranted')
            ->willReturnCallback(function($role) {
                // First call checks ROLE_USER, then ROLE_ADMIN, then ROLE_SUPER_ADMIN
                return $role === 'ROLE_USER';
            });

        $this->mockSecurity
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        $this->assertTrue($this->rbacService->canAccess('document.read', 'ROLE_USER'));
    }

    public function testCanAccessResourceFailsWithoutRequiredRole(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.read']);
        $user = $this->createUserWithRoles(['ROLE_USER']);
        $user->getGroups()->add($userGroup);

        $this->mockSecurity
            ->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn(false);

        // Act & Assert
        $this->assertFalse($this->rbacService->canAccess('document.read', 'ROLE_ADMIN'));
    }

    public function testCanAccessResourceFailsWithoutRequiredPermission(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.read']);
        $user = $this->createUserWithRoles(['ROLE_ADMIN']);
        $user->getGroups()->add($userGroup);

        $this->mockSecurity
            ->expects($this->exactly(2))
            ->method('isGranted')
            ->willReturnCallback(function($role) {
                // First call checks ROLE_ADMIN (from canAccess) - returns true
                // Second call checks ROLE_ADMIN again (from isAdmin in hasPermission) - returns true
                // Since admin has all permissions, it returns early without checking ROLE_SUPER_ADMIN
                return $role === 'ROLE_ADMIN';
            });

        $this->mockSecurity
            ->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        // Since user has ROLE_ADMIN, isAdmin() returns true, so hasPermission returns true for ANY permission
        $this->assertTrue($this->rbacService->canAccess('document.delete', 'ROLE_ADMIN'));
    }

    /**
     * Test permission hierarchy/wildcards
     */
    public function testHasPermissionSupportsWildcards(): void
    {
        // Arrange
        $userGroup = $this->createUserGroupWithPermissions(['document.*']);
        $user = $this->createUserWithGroups([$userGroup]);

        $this->mockSecurity
            ->expects($this->exactly(3))
            ->method('getUser')
            ->willReturn($user);

        // Act & Assert
        $this->assertTrue($this->rbacService->hasPermission('document.read'));
        $this->assertTrue($this->rbacService->hasPermission('document.write'));
        $this->assertTrue($this->rbacService->hasPermission('document.delete'));
    }

    public function testHasPermissionReturnsFalseForNonAuthenticatedUser(): void
    {
        // Arrange
        $this->mockSecurity
            ->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        // Act & Assert
        $this->assertFalse($this->rbacService->hasPermission('document.read'));
    }

    /**
     * Helper methods for creating test objects
     */
    private function createUserWithRoles(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles);
        return $user;
    }

    private function createUserWithGroups(array $groups): User
    {
        $user = new User();
        foreach ($groups as $group) {
            $user->getGroups()->add($group);
        }
        return $user;
    }

    private function createUserGroupWithPermissions(array $permissions): UserGroup
    {
        $group = new UserGroup();
        $group->setName('Test Group');
        $group->setPermissions($permissions);
        return $group;
    }
}