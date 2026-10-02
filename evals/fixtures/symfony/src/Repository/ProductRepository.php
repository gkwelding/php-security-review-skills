<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Product> */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> */
    public function search(?string $term, ?string $category, string $priceOrder): array
    {
        $qb = $this->createQueryBuilder('p')->where('p.active = true');

        if ($term !== null && $term !== '') {
            $qb->andWhere('p.name LIKE :term')->setParameter('term', '%'.$term.'%');
        }

        if ($category !== null && $category !== '') {
            $qb->andWhere("p.category = '".$category."'");
        }

        return $qb->orderBy('p.priceInPence', $priceOrder)->getQuery()->getResult();
    }
}
