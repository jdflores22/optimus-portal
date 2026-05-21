<?php

namespace App\Repository;

use App\Entity\ShippingLine;
use Doctrine\ORM\QueryBuilder;

/**
 * Trait for applying shipping line filters to repository queries
 * 
 * Usage:
 * ```php
 * class ManifestRepository extends ServiceEntityRepository
 * {
 *     use ShippingLineFilterTrait;
 *     
 *     public function findByShippingLine(int $shippingLineId): array
 *     {
 *         $qb = $this->createQueryBuilder('m');
 *         $this->applyShippingLineFilter($qb, $shippingLineId);
 *         return $qb->getQuery()->getResult();
 *     }
 * }
 * ```
 */
trait ShippingLineFilterTrait
{
    /**
     * Apply shipping line filter to a query builder
     * 
     * @param QueryBuilder $qb The query builder to modify
     * @param int|null $shippingLineId The shipping line ID to filter by (null = no filter)
     * @param string $alias The entity alias in the query (default: entity)
     * @return QueryBuilder The modified query builder
     */
    protected function applyShippingLineFilter(
        QueryBuilder $qb, 
        ?int $shippingLineId, 
        string $alias = 'entity'
    ): QueryBuilder {
        if ($shippingLineId !== null) {
            $qb->andWhere("{$alias}.shippingLine = :shippingLineId")
               ->setParameter('shippingLineId', $shippingLineId);
        }
        
        return $qb;
    }

    /**
     * Apply shipping line filter using ShippingLine entity
     * 
     * @param QueryBuilder $qb The query builder to modify
     * @param ShippingLine|null $shippingLine The shipping line entity to filter by
     * @param string $alias The entity alias in the query (default: entity)
     * @return QueryBuilder The modified query builder
     */
    protected function applyShippingLineEntityFilter(
        QueryBuilder $qb, 
        ?ShippingLine $shippingLine, 
        string $alias = 'entity'
    ): QueryBuilder {
        if ($shippingLine !== null) {
            $qb->andWhere("{$alias}.shippingLine = :shippingLine")
               ->setParameter('shippingLine', $shippingLine);
        }
        
        return $qb;
    }

    /**
     * Apply shipping line filter for multiple shipping lines
     * 
     * @param QueryBuilder $qb The query builder to modify
     * @param array $shippingLineIds Array of shipping line IDs
     * @param string $alias The entity alias in the query (default: entity)
     * @return QueryBuilder The modified query builder
     */
    protected function applyMultipleShippingLineFilter(
        QueryBuilder $qb, 
        array $shippingLineIds, 
        string $alias = 'entity'
    ): QueryBuilder {
        if (!empty($shippingLineIds)) {
            $qb->andWhere("{$alias}.shippingLine IN (:shippingLineIds)")
               ->setParameter('shippingLineIds', $shippingLineIds);
        }
        
        return $qb;
    }

    /**
     * Apply shipping line filter with eager loading
     * Joins the shipping line relationship to avoid N+1 queries
     * 
     * @param QueryBuilder $qb The query builder to modify
     * @param int|null $shippingLineId The shipping line ID to filter by
     * @param string $alias The entity alias in the query (default: entity)
     * @return QueryBuilder The modified query builder
     */
    protected function applyShippingLineFilterWithEagerLoad(
        QueryBuilder $qb, 
        ?int $shippingLineId, 
        string $alias = 'entity'
    ): QueryBuilder {
        // Join shipping line for eager loading
        $qb->leftJoin("{$alias}.shippingLine", 'sl')
           ->addSelect('sl');
        
        // Apply filter if shipping line ID provided
        if ($shippingLineId !== null) {
            $qb->andWhere('sl.id = :shippingLineId')
               ->setParameter('shippingLineId', $shippingLineId);
        }
        
        return $qb;
    }
}
