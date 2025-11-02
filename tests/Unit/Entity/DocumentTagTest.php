<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DocumentTag;
use App\Entity\Document;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Doctrine\Common\Collections\ArrayCollection;

class DocumentTagTest extends TestCase
{
    private DocumentTag $documentTag;

    protected function setUp(): void
    {
        $this->documentTag = new DocumentTag();
    }

    public function testDocumentTagCanBeInstantiated(): void
    {
        $this->assertInstanceOf(DocumentTag::class, $this->documentTag);
    }

    public function testDocumentTagHasUuidId(): void
    {
        $this->assertNull($this->documentTag->getId());
        
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        $this->documentTag->setId($uuid);
        $this->assertEquals($uuid, $this->documentTag->getId());
    }

    public function testDocumentTagName(): void
    {
        $name = 'important';
        $this->documentTag->setName($name);
        
        $this->assertEquals($name, $this->documentTag->getName());
    }

    public function testDocumentTagSlug(): void
    {
        $slug = 'important';
        $this->documentTag->setSlug($slug);
        
        $this->assertEquals($slug, $this->documentTag->getSlug());
    }

    public function testDocumentTagDescription(): void
    {
        $this->assertNull($this->documentTag->getDescription());
        
        $description = 'Documents marked as important for immediate attention';
        $this->documentTag->setDescription($description);
        
        $this->assertEquals($description, $this->documentTag->getDescription());
    }

    public function testDocumentTagColor(): void
    {
        $this->assertNull($this->documentTag->getColor());
        
        $color = '#ff5722';
        $this->documentTag->setColor($color);
        
        $this->assertEquals($color, $this->documentTag->getColor());
    }

    public function testDocumentTagIcon(): void
    {
        $this->assertNull($this->documentTag->getIcon());
        
        $icon = 'fas fa-star';
        $this->documentTag->setIcon($icon);
        
        $this->assertEquals($icon, $this->documentTag->getIcon());
    }

    public function testDocumentTagIsActive(): void
    {
        // Default should be active
        $this->assertTrue($this->documentTag->isActive());
        
        $this->documentTag->setIsActive(false);
        $this->assertFalse($this->documentTag->isActive());
        
        $this->documentTag->setIsActive(true);
        $this->assertTrue($this->documentTag->isActive());
    }

    public function testDocumentTagIsSystem(): void
    {
        // Default should be non-system tag
        $this->assertFalse($this->documentTag->isSystem());
        
        $this->documentTag->setIsSystem(true);
        $this->assertTrue($this->documentTag->isSystem());
        
        $this->documentTag->setIsSystem(false);
        $this->assertFalse($this->documentTag->isSystem());
    }

    public function testDocumentTagSortOrder(): void
    {
        // Default should be 0
        $this->assertEquals(0, $this->documentTag->getSortOrder());
        
        $sortOrder = 5;
        $this->documentTag->setSortOrder($sortOrder);
        
        $this->assertEquals($sortOrder, $this->documentTag->getSortOrder());
    }

    public function testDocumentTagUsageCount(): void
    {
        // Default should be 0
        $this->assertEquals(0, $this->documentTag->getUsageCount());
        
        $usageCount = 15;
        $this->documentTag->setUsageCount($usageCount);
        
        $this->assertEquals($usageCount, $this->documentTag->getUsageCount());
    }

    public function testDocumentTagCreatedBy(): void
    {
        $this->assertNull($this->documentTag->getCreatedBy());
        
        $user = new User();
        $user->setEmail('user@example.com');
        $this->documentTag->setCreatedBy($user);
        
        $this->assertEquals($user, $this->documentTag->getCreatedBy());
    }

    public function testDocumentTagDocuments(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, $this->documentTag->getDocuments());
        $this->assertCount(0, $this->documentTag->getDocuments());
    }

    public function testDocumentTagAddDocument(): void
    {
        $document = new Document();
        $document->setFilename('test.pdf');
        
        $this->documentTag->addDocument($document);
        
        $this->assertCount(1, $this->documentTag->getDocuments());
        $this->assertTrue($this->documentTag->getDocuments()->contains($document));
        $this->assertTrue($document->getTags()->contains($this->documentTag));
    }

    public function testDocumentTagRemoveDocument(): void
    {
        $document = new Document();
        $document->setFilename('test.pdf');
        
        $this->documentTag->addDocument($document);
        $this->assertCount(1, $this->documentTag->getDocuments());
        
        $this->documentTag->removeDocument($document);
        $this->assertCount(0, $this->documentTag->getDocuments());
        $this->assertFalse($document->getTags()->contains($this->documentTag));
    }

    public function testDocumentTagHasDocument(): void
    {
        $document = new Document();
        $document->setFilename('test.pdf');
        
        $this->assertFalse($this->documentTag->hasDocument($document));
        
        $this->documentTag->addDocument($document);
        $this->assertTrue($this->documentTag->hasDocument($document));
    }

    public function testDocumentTagIncrementUsageCount(): void
    {
        $this->assertEquals(0, $this->documentTag->getUsageCount());
        
        $this->documentTag->incrementUsageCount();
        $this->assertEquals(1, $this->documentTag->getUsageCount());
        
        $this->documentTag->incrementUsageCount();
        $this->assertEquals(2, $this->documentTag->getUsageCount());
    }

    public function testDocumentTagDecrementUsageCount(): void
    {
        $this->documentTag->setUsageCount(5);
        
        $this->documentTag->decrementUsageCount();
        $this->assertEquals(4, $this->documentTag->getUsageCount());
        
        $this->documentTag->decrementUsageCount();
        $this->assertEquals(3, $this->documentTag->getUsageCount());
    }

    public function testDocumentTagDecrementUsageCountNotBelowZero(): void
    {
        $this->documentTag->setUsageCount(0);
        
        $this->documentTag->decrementUsageCount();
        $this->assertEquals(0, $this->documentTag->getUsageCount());
    }

    public function testDocumentTagUpdateUsageCount(): void
    {
        $document1 = new Document();
        $document1->setFilename('test1.pdf');
        $document2 = new Document();
        $document2->setFilename('test2.pdf');
        $document3 = new Document();
        $document3->setFilename('test3.pdf');
        
        $this->documentTag->addDocument($document1);
        $this->documentTag->addDocument($document2);
        $this->documentTag->addDocument($document3);
        
        $this->documentTag->updateUsageCount();
        $this->assertEquals(3, $this->documentTag->getUsageCount());
    }

    public function testDocumentTagNormalizeName(): void
    {
        $result = DocumentTag::normalizeName('  Important TAG  ');
        $this->assertEquals('important tag', $result);
        
        $result = DocumentTag::normalizeName('URGENT-PRIORITY');
        $this->assertEquals('urgent-priority', $result);
        
        $result = DocumentTag::normalizeName('Tax_Document_2023');
        $this->assertEquals('tax_document_2023', $result);
    }

    public function testDocumentTagGenerateSlug(): void
    {
        $result = DocumentTag::generateSlug('Important Document');
        $this->assertEquals('important-document', $result);
        
        $result = DocumentTag::generateSlug('Tax & Finance 2023');
        $this->assertEquals('tax-finance-2023', $result);
        
        $result = DocumentTag::generateSlug('  Urgent/Priority  ');
        $this->assertEquals('urgent-priority', $result);
    }

    public function testDocumentTagValidateName(): void
    {
        $this->assertTrue(DocumentTag::validateName('valid'));
        $this->assertTrue(DocumentTag::validateName('valid-tag'));
        $this->assertTrue(DocumentTag::validateName('valid_tag_123'));
        
        $this->assertFalse(DocumentTag::validateName(''));
        $this->assertFalse(DocumentTag::validateName('a'));
        $this->assertFalse(DocumentTag::validateName(str_repeat('a', 51)));
        $this->assertFalse(DocumentTag::validateName('invalid tag'));
        $this->assertFalse(DocumentTag::validateName('invalid@tag'));
    }

    public function testDocumentTagGetTagsByUsage(): void
    {
        $tag1 = new DocumentTag();
        $tag1->setName('tag1');
        $tag1->setUsageCount(10);
        
        $tag2 = new DocumentTag();
        $tag2->setName('tag2');
        $tag2->setUsageCount(5);
        
        $tag3 = new DocumentTag();
        $tag3->setName('tag3');
        $tag3->setUsageCount(15);
        
        $tags = [$tag1, $tag2, $tag3];
        $sortedTags = DocumentTag::getTagsByUsage($tags);
        
        $this->assertEquals($tag3, $sortedTags[0]);
        $this->assertEquals($tag1, $sortedTags[1]);
        $this->assertEquals($tag2, $sortedTags[2]);
    }

    public function testDocumentTagGetTagsByName(): void
    {
        $tag1 = new DocumentTag();
        $tag1->setName('zebra');
        
        $tag2 = new DocumentTag();
        $tag2->setName('alpha');
        
        $tag3 = new DocumentTag();
        $tag3->setName('beta');
        
        $tags = [$tag1, $tag2, $tag3];
        $sortedTags = DocumentTag::getTagsByName($tags);
        
        $this->assertEquals($tag2, $sortedTags[0]);
        $this->assertEquals($tag3, $sortedTags[1]);
        $this->assertEquals($tag1, $sortedTags[2]);
    }

    public function testDocumentTagCreateFromString(): void
    {
        $tag = DocumentTag::createFromString('Important Document', null);
        
        $this->assertEquals('important document', $tag->getName());
        $this->assertEquals('important-document', $tag->getSlug());
        $this->assertNull($tag->getCreatedBy());
        $this->assertTrue($tag->isActive());
        $this->assertFalse($tag->isSystem());
    }

    public function testDocumentTagCreateFromStringWithUser(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');
        
        $tag = DocumentTag::createFromString('Test Tag', $user);
        
        $this->assertEquals('test tag', $tag->getName());
        $this->assertEquals('test-tag', $tag->getSlug());
        $this->assertEquals($user, $tag->getCreatedBy());
    }

    public function testDocumentTagCreatedAt(): void
    {
        $this->assertNull($this->documentTag->getCreatedAt());
        
        $createdAt = new \DateTimeImmutable();
        $this->documentTag->setCreatedAt($createdAt);
        
        $this->assertEquals($createdAt, $this->documentTag->getCreatedAt());
    }

    public function testDocumentTagUpdatedAt(): void
    {
        $this->assertNull($this->documentTag->getUpdatedAt());
        
        $updatedAt = new \DateTimeImmutable();
        $this->documentTag->setUpdatedAt($updatedAt);
        
        $this->assertEquals($updatedAt, $this->documentTag->getUpdatedAt());
    }

    public function testDocumentTagToString(): void
    {
        $this->documentTag->setName('important');
        
        $this->assertEquals('important', (string) $this->documentTag);
    }

    public function testDocumentTagToStringWithEmptyName(): void
    {
        $this->assertEquals('', (string) $this->documentTag);
    }

    public function testDocumentTagEquals(): void
    {
        $this->documentTag->setName('test');
        $this->documentTag->setSlug('test');
        
        $otherTag = new DocumentTag();
        $otherTag->setName('test');
        $otherTag->setSlug('test');
        
        $this->assertTrue($this->documentTag->equals($otherTag));
        
        $otherTag->setName('different');
        $this->assertFalse($this->documentTag->equals($otherTag));
    }
}