<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\PlanningCriteria;
use App\Entity\Recipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recipe>
 */
class RecipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recipe::class);
    }

    /**
     * @return list<Recipe>
     */
    public function findByPlanningCriteria(PlanningCriteria $criteria): array
    {
        $queryBuilder = $this->createQueryBuilder('recipe')
            ->orderBy('recipe.preparationTimeMinutes', 'ASC')
            ->addOrderBy('recipe.cookTimeMinutes', 'ASC')
            ->addOrderBy('recipe.estimatedCost', 'ASC');

        if ($criteria->maxCost !== null) {
            $queryBuilder
                ->andWhere('recipe.estimatedCost <= :maxCost')
                ->setParameter('maxCost', $criteria->maxCost);
        }

        if ($criteria->mainIngredient !== null && $criteria->mainIngredient !== '') {
            $queryBuilder
                ->andWhere('recipe.mainIngredient = :mainIngredient')
                ->setParameter('mainIngredient', $criteria->mainIngredient);
        }

        if ($criteria->maxPreparationTime !== null) {
            $queryBuilder
                ->andWhere('recipe.preparationTimeMinutes <= :maxPreparationTime')
                ->setParameter('maxPreparationTime', $criteria->maxPreparationTime);
        }

        if ($criteria->maxCookTime !== null) {
            $queryBuilder
                ->andWhere('recipe.cookTimeMinutes <= :maxCookTime')
                ->setParameter('maxCookTime', $criteria->maxCookTime);
        }

        if ($criteria->maxTotalTime !== null) {
            $queryBuilder
                ->andWhere('(recipe.preparationTimeMinutes + recipe.cookTimeMinutes) <= :maxTotalTime')
                ->setParameter('maxTotalTime', $criteria->maxTotalTime);
        }

        if ($criteria->seasonality !== null && $criteria->seasonality !== '') {
            $queryBuilder
                ->andWhere('recipe.seasonality = :seasonality OR recipe.seasonality = :allYear')
                ->setParameter('seasonality', $criteria->seasonality)
                ->setParameter('allYear', 'toute_annee');
        }

        if ($criteria->difficulty !== null && $criteria->difficulty !== '') {
            $queryBuilder
                ->andWhere('recipe.difficulty = :difficulty')
                ->setParameter('difficulty', $criteria->difficulty);
        }

        if ($criteria->maxCalories !== null) {
            $queryBuilder
                ->andWhere('recipe.caloriesPerPortion IS NULL OR recipe.caloriesPerPortion <= :maxCalories')
                ->setParameter('maxCalories', $criteria->maxCalories);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function findDuplicateByNameAndSourceUrl(string $name, ?string $sourceUrl, ?int $excludeId = null): ?Recipe
    {
        $queryBuilder = $this->createQueryBuilder('recipe')
            ->andWhere('recipe.name = :name')
            ->setParameter('name', trim($name));

        if ($sourceUrl !== null && trim($sourceUrl) !== '') {
            $queryBuilder
                ->andWhere('recipe.sourceUrl = :sourceUrl')
                ->setParameter('sourceUrl', trim($sourceUrl));
        } else {
            $queryBuilder->andWhere('recipe.sourceUrl IS NULL');
        }

        if ($excludeId !== null) {
            $queryBuilder
                ->andWhere('recipe.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return $queryBuilder->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * @param array{
     *   q?: string,
     *   mainIngredient?: string,
     *   seasonality?: string,
     *   difficulty?: string,
     *   maxCost?: float|null,
     *   maxPrep?: int|null,
     *   maxCook?: int|null,
     *   maxTotal?: int|null,
     *   maxCalories?: int|null,
     *   sort?: string,
     *   dir?: string
     * } $filters
     */
    public function countByFilters(array $filters): int
    {
        $queryBuilder = $this->createQueryBuilderForFilters($filters);
        $queryBuilder->resetDQLPart('orderBy');
        $queryBuilder->select('COUNT(recipe.id)');

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array{
     *   q?: string,
     *   mainIngredient?: string,
     *   seasonality?: string,
     *   difficulty?: string,
     *   maxCost?: float|null,
     *   maxPrep?: int|null,
     *   maxCook?: int|null,
     *   maxTotal?: int|null,
     *   maxCalories?: int|null,
     *   sort?: string,
     *   dir?: string
     * } $filters
     * @return list<Recipe>
     */
    public function findByFilters(array $filters, int $limit, int $offset): array
    {
        $queryBuilder = $this->createQueryBuilderForFilters($filters);
        $queryBuilder->setFirstResult($offset)->setMaxResults($limit);

        /** @var list<Recipe> */
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param array{
     *   q?: string,
     *   mainIngredient?: string,
     *   seasonality?: string,
     *   difficulty?: string,
     *   maxCost?: float|null,
     *   maxPrep?: int|null,
     *   maxCook?: int|null,
     *   maxTotal?: int|null,
     *   maxCalories?: int|null,
     *   sort?: string,
     *   dir?: string
     * } $filters
     */
    private function createQueryBuilderForFilters(array $filters): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('recipe');

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $queryBuilder
                ->andWhere('LOWER(recipe.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        $mainIngredient = trim((string) ($filters['mainIngredient'] ?? ''));
        if ($mainIngredient !== '') {
            $queryBuilder
                ->andWhere('recipe.mainIngredient = :mainIngredient')
                ->setParameter('mainIngredient', $mainIngredient);
        }

        $seasonality = trim((string) ($filters['seasonality'] ?? ''));
        if ($seasonality !== '') {
            $queryBuilder
                ->andWhere('recipe.seasonality = :seasonality OR recipe.seasonality = :allYear')
                ->setParameter('seasonality', $seasonality)
                ->setParameter('allYear', 'toute_annee');
        }

        $difficulty = trim((string) ($filters['difficulty'] ?? ''));
        if ($difficulty !== '') {
            $queryBuilder
                ->andWhere('recipe.difficulty = :difficulty')
                ->setParameter('difficulty', $difficulty);
        }

        if (isset($filters['maxCost']) && $filters['maxCost'] !== null) {
            $queryBuilder
                ->andWhere('recipe.estimatedCost <= :maxCost')
                ->setParameter('maxCost', $filters['maxCost']);
        }

        if (isset($filters['maxPrep']) && $filters['maxPrep'] !== null) {
            $queryBuilder
                ->andWhere('recipe.preparationTimeMinutes <= :maxPrep')
                ->setParameter('maxPrep', $filters['maxPrep']);
        }

        if (isset($filters['maxCook']) && $filters['maxCook'] !== null) {
            $queryBuilder
                ->andWhere('recipe.cookTimeMinutes <= :maxCook')
                ->setParameter('maxCook', $filters['maxCook']);
        }

        if (isset($filters['maxTotal']) && $filters['maxTotal'] !== null) {
            $queryBuilder
                ->andWhere('(recipe.preparationTimeMinutes + recipe.cookTimeMinutes) <= :maxTotal')
                ->setParameter('maxTotal', $filters['maxTotal']);
        }

        if (isset($filters['maxCalories']) && $filters['maxCalories'] !== null) {
            $queryBuilder
                ->andWhere('recipe.caloriesPerPortion IS NULL OR recipe.caloriesPerPortion <= :maxCalories')
                ->setParameter('maxCalories', $filters['maxCalories']);
        }

        $sortMap = [
            'name' => 'recipe.name',
            'cost' => 'recipe.estimatedCost',
            'prep' => 'recipe.preparationTimeMinutes',
            'cook' => 'recipe.cookTimeMinutes',
            'total' => '(recipe.preparationTimeMinutes + recipe.cookTimeMinutes)',
            'calories' => 'recipe.caloriesPerPortion',
            'difficulty' => 'recipe.difficulty',
        ];
        $sort = (string) ($filters['sort'] ?? 'name');
        $sortField = $sortMap[$sort] ?? $sortMap['name'];
        $dir = strtoupper((string) ($filters['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $queryBuilder->orderBy($sortField, $dir)->addOrderBy('recipe.name', 'ASC');

        return $queryBuilder;
    }

    /**
     * Suggestions d’autocomplétion sur le nom (même logique LIKE que le filtre {@see createQueryBuilderForFilters}).
     *
     * @return array{items: list<array{id: int, name: string}>, total: int}
     */
    public function suggestByNameSearch(string $q, int $limit, int $offset): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['items' => [], 'total' => 0];
        }

        $needle = '%'.mb_strtolower($q).'%';

        $countQb = $this->createQueryBuilder('recipe')
            ->select('COUNT(recipe.id)')
            ->andWhere('LOWER(recipe.name) LIKE :q')
            ->setParameter('q', $needle);

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $rows = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl')
            ->andWhere('LOWER(recipe.name) LIKE :q')
            ->setParameter('q', $needle)
            ->orderBy('recipe.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'imageUrl' => isset($row['imageUrl']) && $row['imageUrl'] !== null ? (string) $row['imageUrl'] : null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Suggestions planification : nom + infos utiles pour l’affichage riche.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function suggestByNameSearchForPlanning(string $q, int $limit, int $offset): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['items' => [], 'total' => 0];
        }

        $needle = '%'.mb_strtolower($q).'%';

        $countQb = $this->createQueryBuilder('recipe')
            ->select('COUNT(recipe.id)')
            ->andWhere('LOWER(recipe.name) LIKE :q')
            ->setParameter('q', $needle);

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $rowsQb = $this->createQueryBuilder('recipe')
            ->select(
                'recipe.id',
                'recipe.name',
                'recipe.imageUrl',
                'recipe.preparationTimeMinutes',
                'recipe.cookTimeMinutes',
                'recipe.estimatedCost',
                'recipe.caloriesPerPortion',
                'recipe.difficulty',
                'recipe.mainIngredient'
            )
            ->andWhere('LOWER(recipe.name) LIKE :q')
            ->setParameter('q', $needle)
            ->orderBy('recipe.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $rows = $rowsQb->getQuery()->getArrayResult();

        $items = [];
        foreach ($rows as $row) {
            $prep = (int) $row['preparationTimeMinutes'];
            $cook = (int) $row['cookTimeMinutes'];
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'imageUrl' => isset($row['imageUrl']) && $row['imageUrl'] !== null ? (string) $row['imageUrl'] : null,
                'preparationTimeMinutes' => $prep,
                'cookTimeMinutes' => $cook,
                'totalTimeMinutes' => $prep + $cook,
                'estimatedCost' => (string) $row['estimatedCost'],
                'caloriesPerPortion' => isset($row['caloriesPerPortion']) && $row['caloriesPerPortion'] !== null ? (int) $row['caloriesPerPortion'] : null,
                'difficulty' => isset($row['difficulty']) && $row['difficulty'] !== null ? (string) $row['difficulty'] : null,
                'mainIngredient' => (string) $row['mainIngredient'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<int> $excludeRecipeIds
     *
     * @return list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>
     */
    public function findRelatedByRecipeId(int $recipeId, array $excludeRecipeIds, int $limit): array
    {
        $recipe = $this->find($recipeId);
        if (!$recipe instanceof Recipe) {
            return [];
        }

        $mainIngredient = mb_strtolower(trim($recipe->getMainIngredient()));
        if ($mainIngredient === '') {
            return [];
        }

        $excludeIds = $excludeRecipeIds;
        $excludeIds[] = $recipeId;
        $excludeIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => (int) $v,
            $excludeIds
        ), static fn (int $id): bool => $id > 0)));

        $rows = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl', 'recipe.mainIngredient')
            ->andWhere('LOWER(recipe.mainIngredient) = :mainIngredient')
            ->setParameter('mainIngredient', $mainIngredient)
            ->andWhere('recipe.id NOT IN (:excludeIds)')
            ->setParameter('excludeIds', $excludeIds)
            ->orderBy('recipe.mainIngredient', 'ASC')
            ->addOrderBy('recipe.name', 'ASC')
            ->setMaxResults(max(1, $limit));

        $result = [];
        foreach ($rows->getQuery()->getArrayResult() as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'imageUrl' => isset($row['imageUrl']) && $row['imageUrl'] !== null ? (string) $row['imageUrl'] : null,
                'mainIngredient' => (string) $row['mainIngredient'],
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $excludeRecipeIds
     *
     * @return array{
     *   similar: list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>,
     *   different: list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>
     * }
     */
    public function findSuggestionGroupsByRecipeId(int $recipeId, array $excludeRecipeIds, int $similarLimit, int $differentLimit): array
    {
        $recipe = $this->find($recipeId);
        if (!$recipe instanceof Recipe) {
            return ['similar' => [], 'different' => []];
        }

        $mainIngredient = mb_strtolower(trim($recipe->getMainIngredient()));
        if ($mainIngredient === '') {
            return ['similar' => [], 'different' => []];
        }

        $excludeIds = $excludeRecipeIds;
        $excludeIds[] = $recipeId;
        $excludeIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => (int) $v,
            $excludeIds
        ), static fn (int $id): bool => $id > 0)));

        $similarRows = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl', 'recipe.mainIngredient')
            ->andWhere('LOWER(recipe.mainIngredient) = :mainIngredient')
            ->setParameter('mainIngredient', $mainIngredient)
            ->andWhere('recipe.id NOT IN (:excludeIds)')
            ->setParameter('excludeIds', $excludeIds)
            ->orderBy('recipe.name', 'ASC')
            ->setMaxResults(max(1, $similarLimit))
            ->getQuery()
            ->getArrayResult();

        $differentRows = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl', 'recipe.mainIngredient')
            ->andWhere('LOWER(recipe.mainIngredient) != :mainIngredient')
            ->setParameter('mainIngredient', $mainIngredient)
            ->andWhere('recipe.id NOT IN (:excludeIds)')
            ->setParameter('excludeIds', $excludeIds)
            ->orderBy('recipe.name', 'ASC')
            ->setMaxResults(max(1, $differentLimit))
            ->getQuery()
            ->getArrayResult();

        return [
            'similar' => $this->mapSimpleRecipeRows($similarRows),
            'different' => $this->mapSimpleRecipeRows($differentRows),
        ];
    }

    /**
     * @param list<int> $excludeRecipeIds
     *
     * @return list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>
     */
    public function findNeverSelectedForPlanning(int $limit, array $excludeRecipeIds = []): array
    {
        $qb = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl', 'recipe.mainIngredient')
            ->andWhere('recipe.planningSelectionCount = 0')
            ->orderBy('recipe.name', 'ASC')
            ->setMaxResults(max(1, $limit));

        $excludeIds = $this->normalizeExcludeIds($excludeRecipeIds);
        if ($excludeIds !== []) {
            $qb
                ->andWhere('recipe.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', $excludeIds);
        }

        return $this->mapSimpleRecipeRows($qb->getQuery()->getArrayResult());
    }

    /**
     * @param list<int> $excludeRecipeIds
     *
     * @return list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>
     */
    public function findRecentlySelectedForPlanning(int $limit, array $excludeRecipeIds = []): array
    {
        $qb = $this->createQueryBuilder('recipe')
            ->select('recipe.id', 'recipe.name', 'recipe.imageUrl', 'recipe.mainIngredient')
            ->andWhere('recipe.planningLastSelectedAt IS NOT NULL')
            ->orderBy('recipe.planningLastSelectedAt', 'DESC')
            ->addOrderBy('recipe.name', 'ASC')
            ->setMaxResults(max(1, $limit));

        $excludeIds = $this->normalizeExcludeIds($excludeRecipeIds);
        if ($excludeIds !== []) {
            $qb
                ->andWhere('recipe.id NOT IN (:excludeIds)')
                ->setParameter('excludeIds', $excludeIds);
        }

        return $this->mapSimpleRecipeRows($qb->getQuery()->getArrayResult());
    }

    /**
     * @param list<int> $excludeRecipeIds
     *
     * @return list<int>
     */
    private function normalizeExcludeIds(array $excludeRecipeIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $v): int => (int) $v,
            $excludeRecipeIds
        ), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<array{id: int, name: string, imageUrl: ?string, mainIngredient: string}>
     */
    private function mapSimpleRecipeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'imageUrl' => isset($row['imageUrl']) && $row['imageUrl'] !== null ? (string) $row['imageUrl'] : null,
                'mainIngredient' => (string) $row['mainIngredient'],
            ];
        }

        return $out;
    }
}
