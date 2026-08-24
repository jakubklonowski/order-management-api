<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Inventory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inventory>
 */
class InventoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inventory::class);
    }

    /**
     * Locks and returns the current Inventory row for a product.
     *
     * Product::$inventory is the inverse side of a OneToOne relation, so
     * Doctrine always joins it into Product queries and populates the
     * identity map from that (unlocked) read. Without HINT_REFRESH, a
     * concurrent transaction that blocked on this row's lock would, once
     * unblocked, get back the stale cached entity instead of the fresh
     * post-commit values — silently defeating the lock.
     */
    public function findOneByProductIdForUpdate(int $productId): ?Inventory
    {
        $query = $this->createQueryBuilder('i')
            ->andWhere('i.product = :productId')
            ->setParameter('productId', $productId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE);

        $query->setHint(Query::HINT_REFRESH, true);

        return $query->getOneOrNullResult();
    }
}
