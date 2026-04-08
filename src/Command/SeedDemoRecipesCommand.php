<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-demo-recipes', description: 'Charge des recettes de demonstration pour le sprint 1.')]
class SeedDemoRecipesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipeRepository = $this->entityManager->getRepository(Recipe::class);

        if ($recipeRepository->count([]) > 0) {
            $io->warning('Des recettes existent deja, aucun jeu de demonstration ajoute.');

            return Command::SUCCESS;
        }

        $ingredients = [
            'Poulet' => 'g',
            'Saumon' => 'g',
            'Boeuf hache' => 'g',
            'Pates' => 'g',
            'Riz' => 'g',
            'Tomates' => 'piece',
            'Courgettes' => 'piece',
            'Oignons' => 'piece',
            'Creme' => 'ml',
            'Parmesan' => 'g',
        ];

        $ingredientEntities = [];
        foreach ($ingredients as $name => $unit) {
            $ingredient = (new Ingredient())
                ->setName($name)
                ->setDefaultUnit($unit);
            $this->entityManager->persist($ingredient);
            $ingredientEntities[$name] = $ingredient;
        }

        $this->createRecipe(
            'Pates creme poulet',
            25,
            '8.50',
            'poulet',
            'toute_annee',
            [
                ['Poulet', '300.00', 'g'],
                ['Pates', '400.00', 'g'],
                ['Creme', '200.00', 'ml'],
                ['Parmesan', '80.00', 'g'],
                ['Oignons', '1.00', 'piece'],
            ],
            $ingredientEntities
        );

        $this->createRecipe(
            'Saumon riz courgettes',
            35,
            '12.90',
            'poisson',
            'ete',
            [
                ['Saumon', '350.00', 'g'],
                ['Riz', '300.00', 'g'],
                ['Courgettes', '2.00', 'piece'],
                ['Oignons', '1.00', 'piece'],
            ],
            $ingredientEntities
        );

        $this->createRecipe(
            'Boeuf tomates mijote',
            45,
            '10.30',
            'boeuf',
            'hiver',
            [
                ['Boeuf hache', '400.00', 'g'],
                ['Tomates', '4.00', 'piece'],
                ['Oignons', '2.00', 'piece'],
                ['Riz', '250.00', 'g'],
            ],
            $ingredientEntities
        );

        $this->entityManager->flush();
        $io->success('Recettes de demonstration chargees.');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array{0:string, 1:string, 2:string}> $items
     * @param array<string, Ingredient> $ingredientEntities
     */
    private function createRecipe(
        string $name,
        int $prepTime,
        string $cost,
        string $mainIngredient,
        string $seasonality,
        array $items,
        array $ingredientEntities
    ): void {
        $recipe = (new Recipe())
            ->setName($name)
            ->setPreparationTimeMinutes($prepTime)
            ->setEstimatedCost($cost)
            ->setMainIngredient($mainIngredient)
            ->setSeasonality($seasonality);

        foreach ($items as [$ingredientName, $quantity, $unit]) {
            $recipeIngredient = (new RecipeIngredient())
                ->setIngredient($ingredientEntities[$ingredientName])
                ->setIngredientName($ingredientName)
                ->setCategory($ingredientEntities[$ingredientName]->getCategory())
                ->setQuantity($quantity)
                ->setUnit($unit);
            $recipe->addRecipeIngredient($recipeIngredient);
        }

        $this->entityManager->persist($recipe);
    }
}
