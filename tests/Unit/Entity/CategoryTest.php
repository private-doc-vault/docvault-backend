<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Category;
use PHPUnit\Framework\TestCase;
use Doctrine\Common\Collections\ArrayCollection;

class CategoryTest extends TestCase
{
    private Category $category;

    protected function setUp(): void
    {
        $this->category = new Category();
    }

    public function testCategoryCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Category::class, $this->category);
    }

    public function testCategoryHasUuidId(): void
    {
        // Category should auto-generate UUID in constructor
        $this->assertNotNull($this->category->getId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $this->category->getId());

        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->category->setId($uuid);
        $this->assertEquals($uuid, $this->category->getId());
    }

    public function testCategoryName(): void
    {
        $name = 'Financial Documents';
        $this->category->setName($name);
        
        $this->assertEquals($name, $this->category->getName());
    }

    public function testCategorySlug(): void
    {
        $slug = 'financial-documents';
        $this->category->setSlug($slug);
        
        $this->assertEquals($slug, $this->category->getSlug());
    }

    public function testCategoryDescription(): void
    {
        $this->assertNull($this->category->getDescription());
        
        $description = 'All financial documents including invoices, receipts, and statements';
        $this->category->setDescription($description);
        
        $this->assertEquals($description, $this->category->getDescription());
    }

    public function testCategoryColor(): void
    {
        $this->assertNull($this->category->getColor());
        
        $color = '#3498db';
        $this->category->setColor($color);
        
        $this->assertEquals($color, $this->category->getColor());
    }

    public function testCategoryIcon(): void
    {
        $this->assertNull($this->category->getIcon());
        
        $icon = 'fas fa-dollar-sign';
        $this->category->setIcon($icon);
        
        $this->assertEquals($icon, $this->category->getIcon());
    }

    public function testCategoryIsActive(): void
    {
        // Default should be active
        $this->assertTrue($this->category->isActive());
        
        $this->category->setIsActive(false);
        $this->assertFalse($this->category->isActive());
        
        $this->category->setIsActive(true);
        $this->assertTrue($this->category->isActive());
    }

    public function testCategorySortOrder(): void
    {
        // Default should be 0
        $this->assertEquals(0, $this->category->getSortOrder());
        
        $sortOrder = 10;
        $this->category->setSortOrder($sortOrder);
        
        $this->assertEquals($sortOrder, $this->category->getSortOrder());
    }

    public function testCategoryParent(): void
    {
        $this->assertNull($this->category->getParent());
        
        $parent = new Category();
        $this->category->setParent($parent);
        
        $this->assertEquals($parent, $this->category->getParent());
    }

    public function testCategoryChildren(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->category->getChildren());
        $this->assertCount(0, $this->category->getChildren());
    }

    public function testCategoryAddChild(): void
    {
        $child = new Category();
        $child->setName('Child Category');
        
        $this->category->addChild($child);
        
        $this->assertCount(1, $this->category->getChildren());
        $this->assertTrue($this->category->getChildren()->contains($child));
        $this->assertEquals($this->category, $child->getParent());
    }

    public function testCategoryRemoveChild(): void
    {
        $child = new Category();
        $child->setName('Child Category');
        
        $this->category->addChild($child);
        $this->assertCount(1, $this->category->getChildren());
        
        $this->category->removeChild($child);
        $this->assertCount(0, $this->category->getChildren());
        $this->assertNull($child->getParent());
    }

    public function testCategoryHasChildren(): void
    {
        $this->assertFalse($this->category->hasChildren());
        
        $child = new Category();
        $this->category->addChild($child);
        
        $this->assertTrue($this->category->hasChildren());
    }

    public function testCategoryIsRoot(): void
    {
        $this->assertTrue($this->category->isRoot());
        
        $parent = new Category();
        $this->category->setParent($parent);
        
        $this->assertFalse($this->category->isRoot());
    }

    public function testCategoryIsLeaf(): void
    {
        $this->assertTrue($this->category->isLeaf());
        
        $child = new Category();
        $this->category->addChild($child);
        
        $this->assertFalse($this->category->isLeaf());
    }

    public function testCategoryGetLevel(): void
    {
        // Root category should be level 0
        $this->assertEquals(0, $this->category->getLevel());
        
        // Child category should be level 1
        $child = new Category();
        $this->category->addChild($child);
        $this->assertEquals(1, $child->getLevel());
        
        // Grandchild should be level 2
        $grandchild = new Category();
        $child->addChild($grandchild);
        $this->assertEquals(2, $grandchild->getLevel());
    }

    public function testCategoryGetPath(): void
    {
        $this->category->setName('Root');
        $this->assertEquals(['Root'], $this->category->getPath());
        
        $child = new Category();
        $child->setName('Child');
        $this->category->addChild($child);
        $this->assertEquals(['Root', 'Child'], $child->getPath());
        
        $grandchild = new Category();
        $grandchild->setName('Grandchild');
        $child->addChild($grandchild);
        $this->assertEquals(['Root', 'Child', 'Grandchild'], $grandchild->getPath());
    }

    public function testCategoryGetPathAsString(): void
    {
        $this->category->setName('Root');
        $this->assertEquals('Root', $this->category->getPathAsString());
        
        $child = new Category();
        $child->setName('Child');
        $this->category->addChild($child);
        $this->assertEquals('Root > Child', $child->getPathAsString());
        
        $grandchild = new Category();
        $grandchild->setName('Grandchild');
        $child->addChild($grandchild);
        $this->assertEquals('Root > Child > Grandchild', $grandchild->getPathAsString());
    }

    public function testCategoryGetPathAsStringWithCustomSeparator(): void
    {
        $this->category->setName('Root');
        
        $child = new Category();
        $child->setName('Child');
        $this->category->addChild($child);
        
        $this->assertEquals('Root / Child', $child->getPathAsString(' / '));
        $this->assertEquals('Root|Child', $child->getPathAsString('|'));
    }

    public function testCategoryGetAncestors(): void
    {
        $this->assertEquals([], $this->category->getAncestors());
        
        $child = new Category();
        $this->category->addChild($child);
        $this->assertEquals([$this->category], $child->getAncestors());
        
        $grandchild = new Category();
        $child->addChild($grandchild);
        $this->assertEquals([$this->category, $child], $grandchild->getAncestors());
    }

    public function testCategoryGetDescendants(): void
    {
        $this->assertEquals([], $this->category->getDescendants());
        
        $child1 = new Category();
        $child1->setName('Child 1');
        $child2 = new Category();
        $child2->setName('Child 2');
        
        $this->category->addChild($child1);
        $this->category->addChild($child2);
        
        $descendants = $this->category->getDescendants();
        $this->assertCount(2, $descendants);
        $this->assertContains($child1, $descendants);
        $this->assertContains($child2, $descendants);
        
        $grandchild = new Category();
        $grandchild->setName('Grandchild');
        $child1->addChild($grandchild);
        
        $descendants = $this->category->getDescendants();
        $this->assertCount(3, $descendants);
        $this->assertContains($grandchild, $descendants);
    }

    public function testCategoryGetSiblings(): void
    {
        $parent = new Category();
        $parent->setName('Parent');
        
        $child1 = new Category();
        $child1->setName('Child 1');
        $child2 = new Category();
        $child2->setName('Child 2');
        $child3 = new Category();
        $child3->setName('Child 3');
        
        $parent->addChild($child1);
        $parent->addChild($child2);
        $parent->addChild($child3);
        
        $siblings = $child2->getSiblings();
        $this->assertCount(2, $siblings);
        $this->assertContains($child1, $siblings);
        $this->assertContains($child3, $siblings);
        $this->assertNotContains($child2, $siblings);
    }

    public function testCategoryGetSiblingsWhenRoot(): void
    {
        $this->assertEquals([], $this->category->getSiblings());
    }

    public function testCategoryIsAncestorOf(): void
    {
        $child = new Category();
        $grandchild = new Category();
        
        $this->category->addChild($child);
        $child->addChild($grandchild);
        
        $this->assertTrue($this->category->isAncestorOf($child));
        $this->assertTrue($this->category->isAncestorOf($grandchild));
        $this->assertFalse($child->isAncestorOf($this->category));
        $this->assertFalse($grandchild->isAncestorOf($this->category));
    }

    public function testCategoryIsDescendantOf(): void
    {
        $child = new Category();
        $grandchild = new Category();
        
        $this->category->addChild($child);
        $child->addChild($grandchild);
        
        $this->assertTrue($child->isDescendantOf($this->category));
        $this->assertTrue($grandchild->isDescendantOf($this->category));
        $this->assertFalse($this->category->isDescendantOf($child));
        $this->assertFalse($this->category->isDescendantOf($grandchild));
    }

    public function testCategoryDocuments(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->category->getDocuments());
        $this->assertCount(0, $this->category->getDocuments());
    }

    public function testCategoryCreatedAt(): void
    {
        $this->assertNull($this->category->getCreatedAt());
        
        $createdAt = new \DateTimeImmutable();
        $this->category->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->category->getCreatedAt());
    }

    public function testCategoryUpdatedAt(): void
    {
        $this->assertNull($this->category->getUpdatedAt());
        
        $updatedAt = new \DateTimeImmutable();
        $this->category->setUpdatedAt($updatedAt);
        
        $this->assertEquals($updatedAt, $this->category->getUpdatedAt());
    }

    public function testCategoryToString(): void
    {
        $this->category->setName('Financial Documents');
        
        $this->assertEquals('Financial Documents', (string) $this->category);
    }

    public function testCategoryToStringWithEmptyName(): void
    {
        $this->assertEquals('', (string) $this->category);
    }
}