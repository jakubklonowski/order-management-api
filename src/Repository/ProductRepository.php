<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Returns one page of products ordered by id, with stock levels joined in.
     *
     * @param int     $page   1-based page number
     * @param ?string $search product name to be included in LIKE clause
     *
     * @return array{items: Product[], total: int} total counts every match, not only the page
     */
    public function paginate(int $page, int $limit, ?int $categoryId, ?string $search): array
    {
        // pagination built explicitly for Doctrine to use
        // otherwise it would be N+1 queries instead of two
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.inventory', 'i')
            ->addSelect('i')
            ->orderBy('p.id', 'ASC');

        if (null !== $categoryId) {
            $qb->andWhere('p.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('p.name LIKE :search')
                ->setParameter('search', "%{$search}%");
        }

        $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        // fetchJoinCollection not needed as this is one-to-one join relation
        $paginator = new Paginator($qb, false);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
        ];
    }
}
