<?php

declare(strict_types=1);

namespace App\Form;

final class RecipeChoices
{
    /**
     * @return array<string, string>
     */
    public static function mainIngredients(): array
    {
        return [
            'Poulet' => 'poulet',
            'Porc' => 'porc',
            'Poisson' => 'poisson',
            'Boeuf' => 'boeuf',
            'Vegetarien' => 'vegetarien',
            'Pates' => 'pates',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function difficulties(): array
    {
        return [
            'Très facile' => 'Très facile',
            'Facile' => 'Facile',
            'Moyen' => 'Moyen',
            'Difficile' => 'Difficile',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function seasonality(): array
    {
        return [
            'Hiver' => 'hiver',
            'Printemps' => 'printemps',
            'Ete' => 'ete',
            'Automne' => 'automne',
            'Toute annee' => 'toute_annee',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function recipeOrigins(): array
    {
        return [
            'Maison' => 'maison',
            'Jow' => 'jow',
            '750g' => '750g',
            'Marmiton' => 'marmiton',
            'Web (URL)' => 'web',
        ];
    }
}
