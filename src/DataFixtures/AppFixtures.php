<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\DocumentTag;
use App\Entity\UserGroup;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ramsey\Uuid\Uuid;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Create default admin user
        $this->loadDefaultAdmin($manager);

        // Create default categories
        $this->loadDefaultCategories($manager);

        // Create default tags
        $this->loadDefaultTags($manager);

        // Create default user groups
        $this->loadDefaultUserGroups($manager);

        $manager->flush();

        // Assign admin to administrators group (needs flush first to have IDs)
        $this->assignAdminToGroup($manager);

        $manager->flush();
    }

    private function loadDefaultAdmin(ObjectManager $manager): void
    {
        // Check if admin already exists (idempotency)
        $existingAdmin = $manager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@docvault.local']);

        if ($existingAdmin) {
            return;
        }

        $admin = new User();
        $admin->setId(Uuid::uuid4()->toString());
        $admin->setEmail('admin@docvault.local');
        $admin->setFirstName('System');
        $admin->setLastName('Administrator');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $admin->setIsActive(true);
        $admin->setIsVerified(true);
        $admin->setCreatedAt(new \DateTimeImmutable());
        $admin->setUpdatedAt(new \DateTimeImmutable());

        // Default password is 'admin123' - should be changed on first login
        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin123');
        $admin->setPassword($hashedPassword);

        $manager->persist($admin);
    }

    private function loadDefaultCategories(ObjectManager $manager): void
    {
        $categoriesData = [
            [
                'name' => 'General',
                'slug' => 'general',
                'description' => 'General purpose documents',
                'color' => '#6c757d',
                'icon' => 'folder',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Documents',
                'slug' => 'documents',
                'description' => 'Various document types',
                'color' => '#0d6efd',
                'icon' => 'file-text',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Financial',
                'slug' => 'financial',
                'description' => 'Financial documents and records',
                'color' => '#198754',
                'icon' => 'dollar-sign',
                'parent' => null,
                'children' => [
                    [
                        'name' => 'Tax Documents',
                        'slug' => 'tax-documents',
                        'description' => 'Tax returns and related documents',
                        'color' => '#20c997',
                        'icon' => 'file-spreadsheet'
                    ],
                    [
                        'name' => 'Bank Statements',
                        'slug' => 'bank-statements',
                        'description' => 'Bank account statements',
                        'color' => '#0dcaf0',
                        'icon' => 'credit-card'
                    ],
                    [
                        'name' => 'Invoices',
                        'slug' => 'invoices',
                        'description' => 'Invoices and billing documents',
                        'color' => '#ffc107',
                        'icon' => 'receipt'
                    ],
                    [
                        'name' => 'Receipts',
                        'slug' => 'receipts',
                        'description' => 'Purchase receipts',
                        'color' => '#fd7e14',
                        'icon' => 'shopping-bag'
                    ]
                ]
            ],
            [
                'name' => 'Legal',
                'slug' => 'legal',
                'description' => 'Legal documents and contracts',
                'color' => '#dc3545',
                'icon' => 'scale',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Personal',
                'slug' => 'personal',
                'description' => 'Personal documents',
                'color' => '#d63384',
                'icon' => 'user',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Business-related documents',
                'color' => '#6610f2',
                'icon' => 'briefcase',
                'parent' => null,
                'children' => []
            ]
        ];

        foreach ($categoriesData as $categoryData) {
            // Check if category already exists
            $existingCategory = $manager->getRepository(Category::class)
                ->findOneBy(['slug' => $categoryData['slug']]);

            if ($existingCategory) {
                continue;
            }

            $category = new Category();
            $category->setId(Uuid::uuid4()->toString());
            $category->setName($categoryData['name']);
            $category->setSlug($categoryData['slug']);
            $category->setDescription($categoryData['description']);
            $category->setColor($categoryData['color']);
            $category->setIcon($categoryData['icon']);
            $category->setIsActive(true);
            $category->setCreatedAt(new \DateTimeImmutable());
            $category->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($category);

            // Create children categories
            if (!empty($categoryData['children'])) {
                foreach ($categoryData['children'] as $childData) {
                    $existingChild = $manager->getRepository(Category::class)
                        ->findOneBy(['slug' => $childData['slug']]);

                    if ($existingChild) {
                        continue;
                    }

                    $child = new Category();
                    $child->setId(Uuid::uuid4()->toString());
                    $child->setName($childData['name']);
                    $child->setSlug($childData['slug']);
                    $child->setDescription($childData['description']);
                    $child->setColor($childData['color']);
                    $child->setIcon($childData['icon']);
                    $child->setParent($category);
                    $child->setIsActive(true);
                    $child->setCreatedAt(new \DateTimeImmutable());
                    $child->setUpdatedAt(new \DateTimeImmutable());

                    $manager->persist($child);
                }
            }
        }
    }

    private function loadDefaultTags(ObjectManager $manager): void
    {
        $tagsData = [
            ['name' => 'Important', 'color' => '#dc3545', 'description' => 'Important documents requiring attention'],
            ['name' => 'Urgent', 'color' => '#ff6b6b', 'description' => 'Urgent documents needing immediate action'],
            ['name' => 'Archive', 'color' => '#6c757d', 'description' => 'Archived documents for long-term storage'],
            ['name' => 'Draft', 'color' => '#ffc107', 'description' => 'Draft documents in progress'],
            ['name' => 'Reviewed', 'color' => '#198754', 'description' => 'Documents that have been reviewed'],
            ['name' => 'Confidential', 'color' => '#6610f2', 'description' => 'Confidential documents with restricted access'],
            ['name' => 'Public', 'color' => '#0dcaf0', 'description' => 'Public documents available to all'],
            ['name' => 'Internal', 'color' => '#0d6efd', 'description' => 'Internal use documents']
        ];

        foreach ($tagsData as $tagData) {
            // Generate slug from name
            $slug = strtolower(str_replace(' ', '-', $tagData['name']));

            // Check if tag already exists
            $existingTag = $manager->getRepository(DocumentTag::class)
                ->findOneBy(['slug' => $slug]);

            if ($existingTag) {
                continue;
            }

            $tag = new DocumentTag();
            $tag->setId(Uuid::uuid4()->toString());
            $tag->setName($tagData['name']);
            $tag->setSlug($slug);
            $tag->setColor($tagData['color']);
            $tag->setDescription($tagData['description']);
            $tag->setUsageCount(0);
            $tag->setCreatedAt(new \DateTimeImmutable());
            $tag->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($tag);
        }
    }

    private function loadDefaultUserGroups(ObjectManager $manager): void
    {
        $groupsData = [
            [
                'name' => 'Administrators',
                'slug' => 'administrators',
                'description' => 'Full system access and administrative privileges',
                'permissions' => ['admin.users', 'admin.documents', 'admin.categories', 'admin.settings', 'admin.groups', 'admin.audit']
            ],
            [
                'name' => 'Editors',
                'slug' => 'editors',
                'description' => 'Can create, edit, and manage documents',
                'permissions' => ['edit.documents', 'create.documents', 'view.documents', 'delete.own.documents']
            ],
            [
                'name' => 'Viewers',
                'slug' => 'viewers',
                'description' => 'Read-only access to documents',
                'permissions' => ['view.documents']
            ],
            [
                'name' => 'Users',
                'slug' => 'users',
                'description' => 'Standard users with basic document management',
                'permissions' => ['create.documents', 'edit.own.documents', 'view.own.documents', 'delete.own.documents']
            ]
        ];

        foreach ($groupsData as $groupData) {
            // Check if group already exists
            $existingGroup = $manager->getRepository(UserGroup::class)
                ->findOneBy(['slug' => $groupData['slug']]);

            if ($existingGroup) {
                continue;
            }

            $group = new UserGroup();
            $group->setId(Uuid::uuid4()->toString());
            $group->setName($groupData['name']);
            $group->setSlug($groupData['slug']);
            $group->setDescription($groupData['description']);
            $group->setPermissions($groupData['permissions']);
            $group->setIsActive(true);
            $group->setCreatedAt(new \DateTimeImmutable());
            $group->setUpdatedAt(new \DateTimeImmutable());

            $manager->persist($group);
        }
    }

    private function assignAdminToGroup(ObjectManager $manager): void
    {
        $adminUser = $manager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@docvault.local']);

        $adminGroup = $manager->getRepository(UserGroup::class)
            ->findOneBy(['slug' => 'administrators']);

        if ($adminUser && $adminGroup && !$adminUser->getGroups()->contains($adminGroup)) {
            $adminUser->addGroup($adminGroup);
            $manager->persist($adminUser);
        }
    }
}
