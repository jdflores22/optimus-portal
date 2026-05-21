<?php

namespace App\Repository;

use App\Entity\AccreditationSubmission;
use App\Entity\Enum\AccreditationStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccreditationSubmission>
 */
class AccreditationSubmissionRepository extends ServiceEntityRepository
{
    use ShippingLineFilterTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccreditationSubmission::class);
    }

    /**
     * Find accreditation submission by applicant and shipping line
     */
    public function findByApplicantAndShippingLine(User $applicant, int $shippingLineId): ?AccreditationSubmission
    {
        return $this->createQueryBuilder('a')
            ->where('a.applicant = :applicant')
            ->andWhere('a.shippingLine = :shippingLineId')
            ->setParameter('applicant', $applicant)
            ->setParameter('shippingLineId', $shippingLineId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all accreditation submissions for an applicant
     */
    public function findByApplicant(User $applicant, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.shippingLine', 'sl')
            ->addSelect('sl')
            ->where('a.applicant = :applicant')
            ->setParameter('applicant', $applicant)
            ->orderBy('a.submittedAt', 'DESC');

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find accreditation submissions by status
     */
    public function findByStatus(AccreditationStatus $status, ?int $shippingLineId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.applicant', 'u')
            ->addSelect('u')
            ->leftJoin('a.shippingLine', 'sl')
            ->addSelect('sl')
            ->where('a.status = :status')
            ->setParameter('status', $status)
            ->orderBy('a.submittedAt', 'ASC');

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find pending accreditation submissions
     */
    public function findPendingSubmissions(?int $shippingLineId = null): array
    {
        return $this->findByStatus(AccreditationStatus::PENDING, $shippingLineId);
    }

    /**
     * Find approved accreditation submissions
     */
    public function findApprovedSubmissions(?int $shippingLineId = null): array
    {
        return $this->findByStatus(AccreditationStatus::APPROVED, $shippingLineId);
    }

    /**
     * Find accreditation submission by ID with relations
     */
    public function findWithRelations(int $id, ?int $shippingLineId = null): ?AccreditationSubmission
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.applicant', 'u')
            ->addSelect('u')
            ->leftJoin('a.shippingLine', 'sl')
            ->addSelect('sl')
            ->leftJoin('a.evaluator', 'e')
            ->addSelect('e')
            ->leftJoin('a.finalApprover', 'f')
            ->addSelect('f')
            ->where('a.id = :id')
            ->setParameter('id', $id);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find accreditations by shipping line
     */
    public function findByShippingLine(int $shippingLineId, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.applicant', 'u')
            ->addSelect('u')
            ->leftJoin('a.shippingLine', 'sl')
            ->addSelect('sl')
            ->orderBy('a.submittedAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getResult();
    }

    /**
     * Count accreditation submissions by status
     */
    public function countByStatus(AccreditationStatus $status, ?int $shippingLineId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->setParameter('status', $status);

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get accreditation statistics
     */
    public function getStatistics(?int $shippingLineId = null): array
    {
        return [
            'pending' => $this->countByStatus(AccreditationStatus::PENDING, $shippingLineId),
            'approved' => $this->countByStatus(AccreditationStatus::APPROVED, $shippingLineId),
            'rejected' => $this->countByStatus(AccreditationStatus::REJECTED, $shippingLineId),
            'total' => $this->countTotal($shippingLineId)
        ];
    }

    /**
     * Count total accreditation submissions
     */
    private function countTotal(?int $shippingLineId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        $this->applyShippingLineFilter($qb, $shippingLineId, 'a');

        return $qb->getQuery()->getSingleScalarResult();
    }
}
