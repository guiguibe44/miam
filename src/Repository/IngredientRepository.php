<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ingredient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ingredient>
 */
class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * @param array{q?: string, category?: string, sort?: string, dir?: string} $filters
     * @return list<array{ingredient: Ingredient, usageCount: int}>
     */
    public function findForAdmin(array $filters): array
    {
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.recipeIngredients', 'ri')
            ->addSelect('COUNT(ri.id) AS usageCount')
            ->groupBy('i.id');

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $qb->andWhere('LOWER(i.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $qb->andWhere('i.category = :category')
                ->setParameter('category', $category);
        }

        $sort = (string) ($filters['sort'] ?? 'name');
        $dir = strtoupper((string) ($filters['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        match ($sort) {
            'usage' => $qb->orderBy('usageCount', $dir)->addOrderBy('i.name', 'ASC'),
            'category' => $qb->orderBy('i.category', $dir)->addOrderBy('i.name', 'ASC'),
            default => $qb->orderBy('i.name', $dir),
        };

        $rows = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            if (is_array($row) && isset($row[0]) && $row[0] instanceof Ingredient) {
                $rows[] = [
                    'ingredient' => $row[0],
                    'usageCount' => (int) ($row['usageCount'] ?? 0),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{
     *   normalized: string,
     *   target: Ingredient,
     *   duplicates: list<Ingredient>
     * }>
     */
    public function findDuplicateSuggestions(int $maxGroups = 8): array
    {
        /** @var list<Ingredient> $ingredients */
        $ingredients = $this->createQueryBuilder('i')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();

        $groups = [];
        foreach ($ingredients as $ingredient) {
            $key = $this->normalizeName($ingredient->getName());
            if ($key === '') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $ingredient;
        }

        $suggestions = [];
        foreach ($groups as $normalized => $items) {
            if (count($items) < 2) {
                continue;
            }

            usort($items, static fn (Ingredient $a, Ingredient $b): int => strcmp($a->getName(), $b->getName()));
            $target = $items[0];
            $duplicates = array_slice($items, 1);
            if ($duplicates === []) {
                continue;
            }
            $suggestions[] = [
                'normalized' => $normalized,
                'target' => $target,
                'duplicates' => $duplicates,
            ];
        }

        usort(
            $suggestions,
            static fn (array $a, array $b): int => count($b['duplicates']) <=> count($a['duplicates'])
        );

        return array_slice($suggestions, 0, max(1, $maxGroups));
    }

    private function normalizeName(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = str_replace(['œ', 'æ'], ['oe', 'ae'], $v);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($v, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $v = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $v;
            }
        }
        $v = preg_replace('/[^a-z0-9 ]+/u', ' ', $v) ?? $v;
        $v = preg_replace('/\s+/u', ' ', trim($v)) ?? $v;
        if (str_ends_with($v, 's') && mb_strlen($v) > 4) {
            $v = mb_substr($v, 0, mb_strlen($v) - 1);
        }

        return $v;
    }
}
