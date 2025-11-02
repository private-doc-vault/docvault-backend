<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Category;
use App\Entity\DocumentTag;
use App\Entity\UserGroup;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Database seeding service for DocVault
 * 
 * Creates default data for users, categories, tags, and user groups
 */
class DatabaseSeederService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * Seed all default data
     */
    public function seedAll(): void
    {
        $this->seedUserGroups();
        $this->seedCategories();
        $this->seedDocumentTags();
        $this->seedUsers();
        
        $this->entityManager->flush();
    }

    /**
     * Seed default user groups with permissions
     */
    public function seedUserGroups(): void
    {
        $groups = [
            [
                'name' => 'Administrators',
                'description' => 'Full system access with all administrative privileges',
                'permissions' => [
                    'admin.users', 'admin.documents', 'admin.categories', 'admin.settings',
                    'admin.groups', 'admin.system', 'view.audit.logs', 'manage.all.documents'
                ]
            ],
            [
                'name' => 'Editors',
                'description' => 'Can create, edit, and manage documents and categories',
                'permissions' => [
                    'edit.documents', 'create.documents', 'view.documents', 'delete.documents',
                    'manage.categories', 'manage.tags', 'view.all.documents'
                ]
            ],
            [
                'name' => 'Viewers',
                'description' => 'Read-only access to documents and categories',
                'permissions' => [
                    'view.documents', 'view.categories', 'view.tags', 'search.documents'
                ]
            ],
            [
                'name' => 'Users',
                'description' => 'Standard users who can manage their own documents',
                'permissions' => [
                    'create.documents', 'edit.own.documents', 'view.own.documents', 
                    'delete.own.documents', 'view.categories', 'view.tags'
                ]
            ]
        ];

        foreach ($groups as $groupData) {
            $existingGroup = $this->entityManager->getRepository(UserGroup::class)
                ->findOneBy(['name' => $groupData['name']]);
            
            if (!$existingGroup) {
                $group = new UserGroup();
                $group->setId(Uuid::uuid4()->toString());
                $group->setName($groupData['name']);
                $group->setSlug($this->createSlug($groupData['name']));
                $group->setDescription($groupData['description']);
                $group->setPermissions($groupData['permissions']);
                $group->setCreatedAt(new \DateTimeImmutable());
                $group->setUpdatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($group);
            }
        }
    }

    /**
     * Seed default categories with hierarchical structure
     */
    public function seedCategories(): void
    {
        $categories = [
            [
                'name' => 'General',
                'description' => 'General documents and files',
                'color' => '#6B7280',
                'icon' => 'folder',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Documents',
                'description' => 'Official documents and paperwork',
                'color' => '#3B82F6',
                'icon' => 'document-text',
                'parent' => null,
                'children' => []
            ],
            [
                'name' => 'Financial',
                'description' => 'Financial documents and records',
                'color' => '#10B981',
                'icon' => 'currency-dollar',
                'parent' => null,
                'children' => [
                    ['name' => 'Tax Documents', 'color' => '#059669', 'icon' => 'receipt-tax'],
                    ['name' => 'Bank Statements', 'color' => '#047857', 'icon' => 'credit-card'],
                    ['name' => 'Invoices', 'color' => '#065F46', 'icon' => 'document-duplicate'],
                    ['name' => 'Receipts', 'color' => '#064E3B', 'icon' => 'receipt-refund']
                ]
            ],
            [
                'name' => 'Legal',
                'description' => 'Legal documents and contracts',
                'color' => '#8B5CF6',
                'icon' => 'scale',
                'parent' => null,
                'children' => [
                    ['name' => 'Contracts', 'color' => '#7C3AED', 'icon' => 'document-text'],
                    ['name' => 'Agreements', 'color' => '#6D28D9', 'icon' => 'handshake'],
                    ['name' => 'Licenses', 'color' => '#5B21B6', 'icon' => 'badge-check']
                ]
            ],
            [
                'name' => 'Personal',
                'description' => 'Personal documents and files',
                'color' => '#F59E0B',
                'icon' => 'user',
                'parent' => null,
                'children' => [
                    ['name' => 'Identification', 'color' => '#D97706', 'icon' => 'identification'],
                    ['name' => 'Medical', 'color' => '#B45309', 'icon' => 'heart'],
                    ['name' => 'Education', 'color' => '#92400E', 'icon' => 'academic-cap']
                ]
            ],
            [
                'name' => 'Business',
                'description' => 'Business-related documents',
                'color' => '#EF4444',
                'icon' => 'briefcase',
                'parent' => null,
                'children' => [
                    ['name' => 'Reports', 'color' => '#DC2626', 'icon' => 'chart-bar'],
                    ['name' => 'Presentations', 'color' => '#B91C1C', 'icon' => 'presentation-chart-line'],
                    ['name' => 'Proposals', 'color' => '#991B1B', 'icon' => 'document-report']
                ]
            ]
        ];

        foreach ($categories as $categoryData) {
            $parentCategory = $this->createCategory($categoryData);
            
            foreach ($categoryData['children'] as $childData) {
                $childData['parent'] = $parentCategory;
                $childData['description'] = $childData['description'] ?? '';
                $this->createCategory($childData);
            }
        }
    }

    /**
     * Create a single category
     */
    private function createCategory(array $data): Category
    {
        $slug = $this->createSlug($data['name']);
        
        $existingCategory = $this->entityManager->getRepository(Category::class)
            ->findOneBy(['slug' => $slug]);
        
        if ($existingCategory) {
            return $existingCategory;
        }

        $category = new Category();
        $category->setId(Uuid::uuid4()->toString());
        $category->setName($data['name']);
        $category->setSlug($slug);
        $category->setDescription($data['description'] ?? '');
        $category->setColor($data['color']);
        $category->setIcon($data['icon']);
        $category->setParent($data['parent'] ?? null);
        $category->setCreatedAt(new \DateTimeImmutable());
        $category->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($category);
        
        return $category;
    }

    /**
     * Seed default document tags
     */
    public function seedDocumentTags(): void
    {
        $tags = [
            ['name' => 'Important', 'color' => '#EF4444'],
            ['name' => 'Urgent', 'color' => '#F97316'],
            ['name' => 'Archive', 'color' => '#6B7280'],
            ['name' => 'Draft', 'color' => '#84CC16'],
            ['name' => 'Reviewed', 'color' => '#10B981'],
            ['name' => 'Confidential', 'color' => '#8B5CF6'],
            ['name' => 'Public', 'color' => '#3B82F6'],
            ['name' => 'Internal', 'color' => '#F59E0B']
        ];

        foreach ($tags as $tagData) {
            $slug = $this->createSlug($tagData['name']);
            
            $existingTag = $this->entityManager->getRepository(DocumentTag::class)
                ->findOneBy(['slug' => $slug]);
            
            if (!$existingTag) {
                $tag = new DocumentTag();
                $tag->setId(Uuid::uuid4()->toString());
                $tag->setName($tagData['name']);
                $tag->setSlug($slug);
                $tag->setColor($tagData['color']);
                $tag->setUsageCount(0);
                $tag->setCreatedAt(new \DateTimeImmutable());
                $tag->setUpdatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($tag);
            }
        }
    }

    /**
     * Seed default users
     */
    public function seedUsers(): void
    {
        // Create admin user
        $existingAdmin = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'admin@docvault.local']);
        
        if (!$existingAdmin) {
            $adminUser = new User();
            $adminUser->setId(Uuid::uuid4()->toString());
            $adminUser->setEmail('admin@docvault.local');
            $adminUser->setFirstName('System');
            $adminUser->setLastName('Administrator');
            $adminUser->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
            $adminUser->setIsActive(true);
            $adminUser->setIsVerified(true);
            $adminUser->setPreferences([
                'theme' => 'light',
                'language' => 'en',
                'notifications' => true,
                'dateFormat' => 'Y-m-d',
                'timeFormat' => 'H:i'
            ]);
            $adminUser->setCreatedAt(new \DateTimeImmutable());
            $adminUser->setUpdatedAt(new \DateTimeImmutable());
            
            // Set password
            $hashedPassword = $this->passwordHasher->hashPassword($adminUser, 'admin123');
            $adminUser->setPassword($hashedPassword);

            $this->entityManager->persist($adminUser);
            
            // Flush user first to ensure it exists in DB
            $this->entityManager->flush();
            
            // Add to administrators group after user is persisted
            $adminGroup = $this->entityManager->getRepository(UserGroup::class)
                ->findOneBy(['name' => 'Administrators']);
            if ($adminGroup) {
                $adminGroup->addUser($adminUser);
                $this->entityManager->persist($adminGroup);
                $this->entityManager->flush(); // Flush again to persist the relationship
            }
        }
    }

    /**
     * Create URL-safe slug from string
     */
    private function createSlug(string $text): string
    {
        // Convert to lowercase and replace spaces/special chars with hyphens
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        return $slug;
    }
}