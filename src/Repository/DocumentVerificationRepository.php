<?php

namespace App\Repository;

use App\Entity\DocumentVerification;
use App\Entity\Enum\DocumentTemplateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentVerification>
 */
class DocumentVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentVerification::class);
    }

    public function findByToken(string $token): ?DocumentVerification
    {
        return $this->findOneBy(['verificationToken' => $token]);
    }

    public function findOneBySubject(
        DocumentTemplateType $documentType,
        string $subjectType,
        int $subjectId,
    ): ?DocumentVerification {
        return $this->findOneBy([
            'documentType' => $documentType,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ]);
    }
}
