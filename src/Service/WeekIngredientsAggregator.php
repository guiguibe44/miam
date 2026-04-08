<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MealSlot;
use App\Form\IngredientCategories;
use App\Repository\MealSlotRepository;

/**
 * Regroupe les ingrédients des recettes planifiées sur une période (somme par nom + unité).
 */
final class WeekIngredientsAggregator
{
    public function __construct(
        private readonly MealSlotRepository $mealSlotRepository,
    ) {
    }

    /**
     * @return list<array{ingredientName: string, unit: string, quantity: string, lineCount: int, category: string, categoryLabel: string}>
     */
    public function aggregateLines(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $slots = $this->mealSlotRepository->findWithRecipesAndIngredientsInPeriod($periodStart, $periodEnd);

        /** @var array<string, array{ingredientName: string, unit: string, quantity: float, lineCount: int, category: string}> $merged */
        $merged = [];
        $categoryLabels = IngredientCategories::labelsByValue();

        foreach ($slots as $slot) {
            $recipe = $slot->getRecipe();
            if ($recipe === null) {
                continue;
            }

            foreach ($recipe->getRecipeIngredients() as $line) {
                $name = trim($line->getIngredientName());
                if ($name === '') {
                    continue;
                }

                $unit = trim($line->getUnit());
                if ($unit === '') {
                    $unit = 'pce';
                }

                $category = $line->getCategory() !== '' ? $line->getCategory() : 'autre';
                $key = mb_strtolower($category).'|'.mb_strtolower($name).'|'.mb_strtolower($unit);
                $qty = (float) $line->getQuantity();

                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'ingredientName' => $name,
                        'unit' => $unit,
                        'quantity' => 0.0,
                        'lineCount' => 0,
                        'category' => $category,
                    ];
                }

                $merged[$key]['quantity'] += $qty;
                ++$merged[$key]['lineCount'];
            }
        }

        $rows = [];
        foreach ($merged as $row) {
            $rows[] = [
                'ingredientName' => $row['ingredientName'],
                'unit' => $row['unit'],
                'quantity' => $this->formatQuantity($row['quantity']),
                'lineCount' => $row['lineCount'],
                'category' => $row['category'],
                'categoryLabel' => $categoryLabels[$row['category']] ?? 'Autre',
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['categoryLabel'], $a['ingredientName']] <=> [$b['categoryLabel'], $b['ingredientName']]
        );

        return $rows;
    }

    private function formatQuantity(float $q): string
    {
        if (abs($q - round($q)) < 0.001) {
            return (string) (int) round($q);
        }

        return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
    }
}
