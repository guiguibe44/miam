<?php

declare(strict_types=1);

namespace App\Form;

final class IngredientCategories
{
    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'Légumes' => 'legumes',
            'Fruits' => 'fruits',
            'Épices' => 'epices',
            'Viande' => 'viande',
            'Charcuterie' => 'charcuterie',
            'Poisson' => 'poisson',
            'Produits laitiers' => 'produits_laitiers',
            'Féculents' => 'feculents',
            'Légumineuses' => 'legumineuses',
            'Sucré' => 'sucre',
            'Boissons' => 'boissons',
            'Autre' => 'autre',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labelsByValue(): array
    {
        $labels = [];
        foreach (self::choices() as $label => $value) {
            $labels[$value] = $label;
        }

        return $labels;
    }
}
