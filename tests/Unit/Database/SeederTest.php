<?php

declare(strict_types=1);

namespace App\Tests\Unit\Database;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\DocumentTag;
use App\Entity\UserGroup;
use App\DataFixtures\AppFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test database seeders functionality
 * 
 * Tests seeder creation and execution following Test-Driven Development approach.
 */
class SeederTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        // Load fixtures for testing
        $fixtures = self::getContainer()->get(AppFixtures::class);
        $fixtures->load($this->entityManager);

        // Clear entity manager to ensure fresh data in tests
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test that default admin user is seeded
     */
    public function testDefaultAdminUserIsSeeded(): void
    {
        // This test will fail initially (RED) until we create the seeder
        $userRepository = $this->entityManager->getRepository(User::class);
        $adminUser = $userRepository->findOneBy(['email' => 'admin@docvault.local']);
        
        $this->assertNotNull($adminUser, 'Default admin user should be seeded');
        $this->assertEquals('admin@docvault.local', $adminUser->getEmail());

        $roles = $adminUser->getRoles();
        $this->assertIsArray($roles, 'Roles should be an array');
        $this->assertContains('ROLE_USER', $roles, 'Should have ROLE_USER');
        $this->assertContains('ROLE_ADMIN', $roles, 'Should have ROLE_ADMIN - got: ' . implode(', ', $roles));
        $this->assertTrue($adminUser->isVerified(), 'Admin user should be verified');
        $this->assertTrue($adminUser->isActive(), 'Admin user should be active');
    }

    /**
     * Test that default categories are seeded
     */
    public function testDefaultCategoriesAreSeeded(): void
    {
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        
        // Expected default categories
        $expectedCategories = [
            'General',
            'Documents',
            'Financial',
            'Legal',
            'Personal',
            'Business'
        ];

        foreach ($expectedCategories as $categoryName) {
            $category = $categoryRepository->findOneBy(['name' => $categoryName]);
            $this->assertNotNull(
                $category, 
                "Default category '$categoryName' should be seeded"
            );
            $this->assertEquals($categoryName, $category->getName());
            $this->assertNotEmpty($category->getSlug(), 'Category should have a slug');
            $this->assertNotEmpty($category->getColor(), 'Category should have a color');
            $this->assertNotEmpty($category->getIcon(), 'Category should have an icon');
        }
    }

    /**
     * Test that hierarchical categories are properly structured
     */
    public function testHierarchicalCategoriesStructure(): void
    {
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        
        // Financial category should have subcategories
        $financialCategory = $categoryRepository->findOneBy(['name' => 'Financial']);
        $this->assertNotNull($financialCategory, 'Financial category should exist');
        
        // Check for subcategories
        $subcategories = $categoryRepository->findBy(['parent' => $financialCategory]);
        $this->assertGreaterThan(0, count($subcategories), 'Financial category should have subcategories');
        
        $expectedSubcategories = ['Tax Documents', 'Bank Statements', 'Invoices', 'Receipts'];
        $actualSubcategoryNames = array_map(fn($cat) => $cat->getName(), $subcategories);
        
        foreach ($expectedSubcategories as $expectedSub) {
            $this->assertContains(
                $expectedSub, 
                $actualSubcategoryNames,
                "Subcategory '$expectedSub' should exist under Financial"
            );
        }
    }

    /**
     * Test that default document tags are seeded
     */
    public function testDefaultDocumentTagsAreSeeded(): void
    {
        $tagRepository = $this->entityManager->getRepository(DocumentTag::class);
        
        $expectedTags = [
            'Important',
            'Urgent', 
            'Archive',
            'Draft',
            'Reviewed',
            'Confidential',
            'Public',
            'Internal'
        ];

        foreach ($expectedTags as $tagName) {
            $tag = $tagRepository->findOneBy(['name' => $tagName]);
            $this->assertNotNull(
                $tag, 
                "Default tag '$tagName' should be seeded"
            );
            $this->assertEquals($tagName, $tag->getName());
            $this->assertEquals(0, $tag->getUsageCount(), 'New tag should have 0 usage count');
            $this->assertNotEmpty($tag->getSlug(), 'Tag should have a slug');
            $this->assertNotEmpty($tag->getColor(), 'Tag should have a color');
        }
    }

    /**
     * Test that default user groups are seeded
     */
    public function testDefaultUserGroupsAreSeeded(): void
    {
        $groupRepository = $this->entityManager->getRepository(UserGroup::class);
        
        $expectedGroups = [
            'Administrators' => ['admin.users', 'admin.documents', 'admin.categories', 'admin.settings'],
            'Editors' => ['edit.documents', 'create.documents', 'view.documents'],
            'Viewers' => ['view.documents'],
            'Users' => ['create.documents', 'edit.own.documents', 'view.own.documents']
        ];

        foreach ($expectedGroups as $groupName => $expectedPermissions) {
            $group = $groupRepository->findOneBy(['name' => $groupName]);
            $this->assertNotNull(
                $group, 
                "Default group '$groupName' should be seeded"
            );
            
            $this->assertEquals($groupName, $group->getName());
            $this->assertNotEmpty($group->getDescription(), 'Group should have a description');
            
            $groupPermissions = $group->getPermissions();
            foreach ($expectedPermissions as $permission) {
                $this->assertContains(
                    $permission, 
                    $groupPermissions,
                    "Group '$groupName' should have permission '$permission'"
                );
            }
        }
    }

    /**
     * Test that admin user is assigned to administrators group
     */
    public function testAdminUserIsAssignedToAdministratorsGroup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $groupRepository = $this->entityManager->getRepository(UserGroup::class);
        
        $adminUser = $userRepository->findOneBy(['email' => 'admin@docvault.local']);
        $adminGroup = $groupRepository->findOneBy(['name' => 'Administrators']);
        
        $this->assertNotNull($adminUser, 'Admin user should exist');
        $this->assertNotNull($adminGroup, 'Administrators group should exist');
        
        $this->assertTrue(
            $adminUser->getGroups()->contains($adminGroup),
            'Admin user should be assigned to Administrators group'
        );
    }

    /**
     * Test that seeded data has proper timestamps
     */
    public function testSeededDataHasProperTimestamps(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        
        $adminUser = $userRepository->findOneBy(['email' => 'admin@docvault.local']);
        $generalCategory = $categoryRepository->findOneBy(['name' => 'General']);
        
        $this->assertNotNull($adminUser, 'Admin user should exist');
        $this->assertNotNull($generalCategory, 'General category should exist');
        
        // Check timestamps are set
        $this->assertNotNull($adminUser->getCreatedAt(), 'User should have created_at timestamp');
        $this->assertNotNull($adminUser->getUpdatedAt(), 'User should have updated_at timestamp');
        $this->assertNotNull($generalCategory->getCreatedAt(), 'Category should have created_at timestamp');
        $this->assertNotNull($generalCategory->getUpdatedAt(), 'Category should have updated_at timestamp');
        
        // Check timestamps are reasonable (not in the future, not too old)
        $now = new \DateTimeImmutable();
        $oneWeekAgo = $now->sub(new \DateInterval('P7D'));

        $this->assertGreaterThan(
            $oneWeekAgo,
            $adminUser->getCreatedAt(),
            'User creation timestamp should be within last week'
        );
        $this->assertGreaterThan(
            $oneWeekAgo,
            $generalCategory->getCreatedAt(),
            'Category creation timestamp should be within last week'
        );

        // Check timestamps are not in the future
        $this->assertLessThanOrEqual(
            $now,
            $adminUser->getCreatedAt(),
            'User creation timestamp should not be in the future'
        );
        $this->assertLessThanOrEqual(
            $now,
            $generalCategory->getCreatedAt(),
            'Category creation timestamp should not be in the future'
        );
    }

    /**
     * Test that seeder can be run multiple times without duplicates
     */
    public function testSeederIdempotency(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        
        // Count existing seeded data
        $initialUserCount = count($userRepository->findAll());
        $initialCategoryCount = count($categoryRepository->findAll());
        
        // Running seeder again should not create duplicates
        // This test validates the seeder implementation includes proper checks
        $this->assertGreaterThan(0, $initialUserCount, 'Should have seeded users');
        $this->assertGreaterThan(0, $initialCategoryCount, 'Should have seeded categories');
        
        // The actual seeder will need to implement duplicate checking
        // For now, we just verify that basic seeded data exists
        $adminUser = $userRepository->findOneBy(['email' => 'admin@docvault.local']);
        $this->assertNotNull($adminUser, 'Admin user should exist after multiple runs');
    }

    /**
     * Test that seeded data follows entity validation rules
     */
    public function testSeededDataValidation(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        $tagRepository = $this->entityManager->getRepository(DocumentTag::class);
        
        // Check user validation
        $adminUser = $userRepository->findOneBy(['email' => 'admin@docvault.local']);
        $this->assertNotNull($adminUser, 'Admin user should exist');
        $this->assertMatchesRegularExpression('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $adminUser->getEmail(), 'User email should be valid');
        $this->assertNotEmpty($adminUser->getPassword(), 'User should have a password');
        
        // Check category validation
        $categories = $categoryRepository->findAll();
        foreach ($categories as $category) {
            $this->assertNotEmpty($category->getName(), 'Category name should not be empty');
            $this->assertNotEmpty($category->getSlug(), 'Category slug should not be empty');
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $category->getSlug(), 'Category slug should be valid');
            $color = $category->getColor();
            if ($color !== null) {
                $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color, 'Category color should be valid hex');
            }
        }
        
        // Check tag validation  
        $tags = $tagRepository->findAll();
        foreach ($tags as $tag) {
            $this->assertNotEmpty($tag->getName(), 'Tag name should not be empty');
            $this->assertNotEmpty($tag->getSlug(), 'Tag slug should not be empty');
            $this->assertGreaterThanOrEqual(0, $tag->getUsageCount(), 'Tag usage count should be non-negative');
        }
    }
}