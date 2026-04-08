<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MealSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MealSlot>
 */
class MealSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealSlot::class);
    }

    /**
     * Créneaux avec recette et ingrédients chargés, sur une plage de dates (inclusive, jours entiers).
     *
     * @return list<MealSlot>
     */
    public function findWithRecipesAndIngredientsInPeriod(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(0, 0, 0);

        /** @var list<MealSlot> */
        return $this->createQueryBuilder('ms')
            ->select('ms', 'r', 'ri')
            ->innerJoin('ms.recipe', 'r')
            ->leftJoin('r.recipeIngredients', 'ri')
            ->where('ms.servedOn >= :start')
            ->andWhere('ms.servedOn <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('ms.servedOn', 'ASC')
            ->addOrderBy('ms.mealType', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
