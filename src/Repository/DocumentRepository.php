<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Find completed documents older than a specific date
     *
     * @param \DateTimeInterface $cutoffDate Documents updated before this date
     * @return Document[]
     */
    public function findCompletedDocumentsOlderThan(\DateTimeInterface $cutoffDate): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.processingStatus = :status')
            ->andWhere('d.updatedAt < :cutoffDate')
            ->setParameter('status', 'completed')
            ->setParameter('cutoffDate', $cutoffDate)
            ->orderBy('d.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
