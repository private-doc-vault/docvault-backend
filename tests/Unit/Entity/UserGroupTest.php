<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\UserGroup;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Doctrine\Common\Collections\ArrayCollection;

class UserGroupTest extends TestCase
{
    private UserGroup $userGroup;

    protected function setUp(): void
    {
        $this->userGroup = new UserGroup();
    }

    public function testUserGroupCanBeInstantiated(): void
    {
        $this->assertInstanceOf(UserGroup::class, $this->userGroup);
    }

    public function testUserGroupHasUuidId(): void
    {
        // UserGroup should auto-generate UUID in constructor
        $this->assertNotNull($this->userGroup->getId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->userGroup->getId());

        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->userGroup->setId($uuid);
        $this->assertEquals($uuid, $this->userGroup->getId());
    }

    public function testUserGroupName(): void
    {
        $name = 'Finance Team';
        $this->userGroup->setName($name);
        
        $this->assertEquals($name, $this->userGroup->getName());
    }

    public function testUserGroupSlug(): void
    {
        $slug = 'finance-team';
        $this->userGroup->setSlug($slug);
        
        $this->assertEquals($slug, $this->userGroup->getSlug());
    }

    public function testUserGroupDescription(): void
    {
        $this->assertNull($this->userGroup->getDescription());
        
        $description = 'Group for finance department members with access to financial documents';
        $this->userGroup->setDescription($description);
        
        $this->assertEquals($description, $this->userGroup->getDescription());
    }

    public function testUserGroupIsActive(): void
    {
        // Default should be active
        $this->assertTrue($this->userGroup->isActive());
        
        $this->userGroup->setIsActive(false);
        $this->assertFalse($this->userGroup->isActive());
        
        $this->userGroup->setIsActive(true);
        $this->assertTrue($this->userGroup->isActive());
    }

    public function testUserGroupIsSystem(): void
    {
        // Default should be non-system group
        $this->assertFalse($this->userGroup->isSystem());
        
        $this->userGroup->setIsSystem(true);
        $this->assertTrue($this->userGroup->isSystem());
        
        $this->userGroup->setIsSystem(false);
        $this->assertFalse($this->userGroup->isSystem());
    }

    public function testUserGroupPermissions(): void
    {
        // Default should be empty array
        $this->assertEquals([], $this->userGroup->getPermissions());
        
        $permissions = ['document.view', 'document.create', 'document.edit'];
        $this->userGroup->setPermissions($permissions);
        
        $this->assertEquals($permissions, $this->userGroup->getPermissions());
    }

    public function testUserGroupHasPermission(): void
    {
        $permissions = ['document.view', 'document.create'];
        $this->userGroup->setPermissions($permissions);
        
        $this->assertTrue($this->userGroup->hasPermission('document.view'));
        $this->assertTrue($this->userGroup->hasPermission('document.create'));
        $this->assertFalse($this->userGroup->hasPermission('document.delete'));
    }

    public function testUserGroupAddPermission(): void
    {
        $this->userGroup->addPermission('document.view');
        $this->userGroup->addPermission('document.create');
        
        $this->assertTrue($this->userGroup->hasPermission('document.view'));
        $this->assertTrue($this->userGroup->hasPermission('document.create'));
        
        $permissions = $this->userGroup->getPermissions();
        $this->assertContains('document.view', $permissions);
        $this->assertContains('document.create', $permissions);
    }

    public function testUserGroupAddPermissionNoDuplicates(): void
    {
        $this->userGroup->addPermission('document.view');
        $this->userGroup->addPermission('document.view'); // Add same permission again
        
        $permissions = $this->userGroup->getPermissions();
        $this->assertCount(1, $permissions);
        $this->assertEquals(['document.view'], $permissions);
    }

    public function testUserGroupRemovePermission(): void
    {
        $this->userGroup->setPermissions(['document.view', 'document.create', 'document.delete']);
        
        $this->userGroup->removePermission('document.create');
        
        $permissions = $this->userGroup->getPermissions();
        $this->assertNotContains('document.create', $permissions);
        $this->assertContains('document.view', $permissions);
        $this->assertContains('document.delete', $permissions);
    }

    public function testUserGroupUsers(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->userGroup->getUsers());
        $this->assertCount(0, $this->userGroup->getUsers());
    }

    public function testUserGroupAddUser(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $this->userGroup->addUser($user);
        
        $this->assertCount(1, $this->userGroup->getUsers());
        $this->assertTrue($this->userGroup->getUsers()->contains($user));
        $this->assertTrue($user->getGroups()->contains($this->userGroup));
    }

    public function testUserGroupRemoveUser(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $this->userGroup->addUser($user);
        $this->assertCount(1, $this->userGroup->getUsers());
        
        $this->userGroup->removeUser($user);
        $this->assertCount(0, $this->userGroup->getUsers());
        $this->assertFalse($user->getGroups()->contains($this->userGroup));
    }

    public function testUserGroupHasUser(): void
    {
        $user1 = new User();
        $user1->setEmail('user1@example.com');
        $user2 = new User();
        $user2->setEmail('user2@example.com');
        
        $this->assertFalse($this->userGroup->hasUser($user1));
        
        $this->userGroup->addUser($user1);
        $this->assertTrue($this->userGroup->hasUser($user1));
        $this->assertFalse($this->userGroup->hasUser($user2));
    }

    public function testUserGroupGetUserCount(): void
    {
        $this->assertEquals(0, $this->userGroup->getUserCount());
        
        $user1 = new User();
        $user1->setEmail('user1@example.com');
        $user2 = new User();
        $user2->setEmail('user2@example.com');
        
        $this->userGroup->addUser($user1);
        $this->assertEquals(1, $this->userGroup->getUserCount());
        
        $this->userGroup->addUser($user2);
        $this->assertEquals(2, $this->userGroup->getUserCount());
    }

    public function testUserGroupCreatedBy(): void
    {
        $this->assertNull($this->userGroup->getCreatedBy());
        
        $user = new User();
        $user->setEmail('admin@example.com');
        $this->userGroup->setCreatedBy($user);
        
        $this->assertEquals($user, $this->userGroup->getCreatedBy());
    }

    public function testUserGroupCreatedAt(): void
    {
        $this->assertNull($this->userGroup->getCreatedAt());
        
        $createdAt = new \DateTimeImmutable();
        $this->userGroup->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->userGroup->getCreatedAt());
    }

    public function testUserGroupUpdatedAt(): void
    {
        $this->assertNull($this->userGroup->getUpdatedAt());
        
        $updatedAt = new \DateTimeImmutable();
        $this->userGroup->setUpdatedAt($updatedAt);
        
        $this->assertEquals($updatedAt, $this->userGroup->getUpdatedAt());
    }

    public function testUserGroupGenerateSlug(): void
    {
        $result = UserGroup::generateSlug('Finance Team');
        $this->assertEquals('finance-team', $result);
        
        $result = UserGroup::generateSlug('HR & Admin Department');
        $this->assertEquals('hr-admin-department', $result);
        
        $result = UserGroup::generateSlug('  Development/QA Team  ');
        $this->assertEquals('development-qa-team', $result);
    }

    public function testUserGroupValidateName(): void
    {
        $this->assertTrue(UserGroup::validateName('Finance Team'));
        $this->assertTrue(UserGroup::validateName('HR'));
        $this->assertTrue(UserGroup::validateName('Development & QA'));
        
        $this->assertFalse(UserGroup::validateName(''));
        $this->assertFalse(UserGroup::validateName('A'));
        $this->assertFalse(UserGroup::validateName(str_repeat('A', 101))); // Too long
    }

    public function testUserGroupValidatePermission(): void
    {
        $this->assertTrue(UserGroup::validatePermission('document.view'));
        $this->assertTrue(UserGroup::validatePermission('user.create'));
        $this->assertTrue(UserGroup::validatePermission('admin.system.manage'));
        
        $this->assertFalse(UserGroup::validatePermission(''));
        $this->assertFalse(UserGroup::validatePermission('invalid'));
        $this->assertFalse(UserGroup::validatePermission('invalid.'));
        $this->assertFalse(UserGroup::validatePermission('.invalid'));
        $this->assertFalse(UserGroup::validatePermission('document..view'));
    }

    public function testUserGroupGetPermissionsByType(): void
    {
        $permissions = [
            'document.view',
            'document.create',
            'document.edit',
            'user.view',
            'user.create',
            'admin.system'
        ];
        
        $this->userGroup->setPermissions($permissions);
        
        $documentPermissions = $this->userGroup->getPermissionsByType('document');
        $this->assertEquals(['document.view', 'document.create', 'document.edit'], $documentPermissions);
        
        $userPermissions = $this->userGroup->getPermissionsByType('user');
        $this->assertEquals(['user.view', 'user.create'], $userPermissions);
        
        $adminPermissions = $this->userGroup->getPermissionsByType('admin');
        $this->assertEquals(['admin.system'], $adminPermissions);
        
        $nonexistentPermissions = $this->userGroup->getPermissionsByType('category');
        $this->assertEquals([], $nonexistentPermissions);
    }

    public function testUserGroupHasAnyPermission(): void
    {
        $this->userGroup->setPermissions(['document.view', 'user.create']);
        
        $this->assertTrue($this->userGroup->hasAnyPermission(['document.view', 'document.edit']));
        $this->assertTrue($this->userGroup->hasAnyPermission(['user.create']));
        $this->assertFalse($this->userGroup->hasAnyPermission(['document.delete', 'admin.system']));
    }

    public function testUserGroupHasAllPermissions(): void
    {
        $this->userGroup->setPermissions(['document.view', 'document.create', 'user.view']);
        
        $this->assertTrue($this->userGroup->hasAllPermissions(['document.view', 'document.create']));
        $this->assertTrue($this->userGroup->hasAllPermissions(['document.view']));
        $this->assertFalse($this->userGroup->hasAllPermissions(['document.view', 'document.delete']));
        $this->assertFalse($this->userGroup->hasAllPermissions(['admin.system']));
    }

    public function testUserGroupCreateSystemGroup(): void
    {
        $group = UserGroup::createSystemGroup('Administrators', ['admin.system', 'user.manage']);
        
        $this->assertEquals('Administrators', $group->getName());
        $this->assertEquals('administrators', $group->getSlug());
        $this->assertTrue($group->isSystem());
        $this->assertTrue($group->isActive());
        $this->assertEquals(['admin.system', 'user.manage'], $group->getPermissions());
    }

    public function testUserGroupToString(): void
    {
        $this->userGroup->setName('Finance Team');
        
        $this->assertEquals('Finance Team', (string) $this->userGroup);
    }

    public function testUserGroupToStringWithEmptyName(): void
    {
        $this->assertEquals('', (string) $this->userGroup);
    }
}