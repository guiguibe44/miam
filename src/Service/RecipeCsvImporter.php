<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Form\RecipeChoices;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import de recettes depuis un fichier CSV (UTF-8, virgule, guillemets RFC 4180).
 *
 * Modèle : voir public/recettes-import-modele.csv
 */
final class RecipeCsvImporter
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const MAX_DATA_ROWS = 500;

    /** @var list<string> */
    private const REQUIRED_CANONICAL = ['name', 'estimated_cost', 'main_ingredient'];

    /** @var array<string, string> normalisation nom de colonne (ligne d'en-tête) */
    private const HEADER_MAP = [
        'name' => 'name',
        'nom' => 'name',
        'preparation_minutes' => 'preparation_minutes',
        'temps_preparation_min' => 'preparation_minutes',
        'prep_min' => 'preparation_minutes',
        'cook_minutes' => 'cook_minutes',
        'temps_cuisson_min' => 'cook_minutes',
        'cook_min' => 'cook_minutes',
        'estimated_cost' => 'estimated_cost',
        'cout_estime' => 'estimated_cost',
        'cout' => 'estimated_cost',
        'main_ingredient' => 'main_ingredient',
        'aliment_principal' => 'main_ingredient',
        'seasonality' => 'seasonality',
        'saison' => 'seasonality',
        'saisonnalite' => 'seasonality',
        'difficulty' => 'difficulty',
        'difficulte' => 'difficulty',
        'calories' => 'calories',
        'calories_par_portion' => 'calories',
        'image_url' => 'image_url',
        'url_image' => 'image_url',
        'source_url' => 'source_url',
        'url_source' => 'source_url',
        'portion_price_label' => 'portion_price_label',
        'libelle_prix' => 'portion_price_label',
        'ingredients' => 'ingredients',
    ];

    public function __construct(
        private readonly RecipeRepository $recipeRepository,
    ) {
    }

    /**
     * @return array{imported: int, updated: int, skipped: int, skippedNames: list<string>, errors: list<string>}
     */
    public function importFromUploadedFile(UploadedFile $file, EntityManagerInterface $entityManager, bool $overwriteExisting = false): array
    {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            return [
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'skippedNames' => [],
                'errors' => [sprintf('Fichier trop volumineux (max %d Mo).', (int) (self::MAX_FILE_BYTES / 1024 / 1024))],
            ];
        }

        $path = $file->getPathname();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'skippedNames' => [], 'errors' => ['Impossible de lire le fichier.']];
        }

        try {
            $bom = fread($handle, 3);
            if ($bom === false) {
                return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'skippedNames' => [], 'errors' => ['Fichier vide.']];
            }
            if ($bom === '' || $bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $headerRow = fgetcsv($handle, 0, ',', '"', '\\');
            if ($headerRow === false || $headerRow === [null] || $headerRow === []) {
                return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'skippedNames' => [], 'errors' => ['En-tête CSV manquant ou illisible.']];
            }

            $columnIndex = $this->buildColumnIndex($headerRow);
            foreach (self::REQUIRED_CANONICAL as $req) {
                if (!isset($columnIndex[$req])) {
                    return [
                        'imported' => 0,
                        'updated' => 0,
                        'skipped' => 0,
                        'skippedNames' => [],
                        'errors' => [
                            sprintf(
                                'Colonne obligatoire manquante : %s. Utilisez le fichier modele (recettes-import-modele.csv).',
                                $req
                            ),
                        ],
                    ];
                }
            }

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $skippedNames = [];
            /** @var list<string> $errors */
            $errors = [];
            $lineNumber = 1;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                ++$lineNumber;
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                if ($imported + $skipped >= self::MAX_DATA_ROWS) {
                    $errors[] = sprintf('Limite de %d lignes de donnees atteinte ; arrete.', self::MAX_DATA_ROWS);
                    break;
                }

                try {
                    $recipe = $this->buildRecipeFromRow($row, $columnIndex, $lineNumber);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();

                    continue;
                }

                $duplicate = $this->recipeRepository->findDuplicateByNameAndSourceUrl(
                    $recipe->getName(),
                    $recipe->getSourceUrl()
                );
                if ($duplicate instanceof Recipe) {
                    if (!$overwriteExisting) {
                        ++$skipped;
                        $skippedNames[] = $recipe->getName();

                        continue;
                    }

                    $this->applyRecipeData($duplicate, $recipe);
                    foreach ($duplicate->getRecipeIngredients() as $ri) {
                        $this->linkIngredientEntity($ri, $entityManager);
                    }
                    $entityManager->flush();
                    ++$updated;

                    continue;
                }

                foreach ($recipe->getRecipeIngredients() as $ri) {
                    $this->linkIngredientEntity($ri, $entityManager);
                }

                $entityManager->persist($recipe);
                $entityManager->flush();
                ++$imported;
            }

            return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'skippedNames' => $skippedNames, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    private function applyRecipeData(Recipe $target, Recipe $source): void
    {
        $target
            ->setPreparationTimeMinutes($source->getPreparationTimeMinutes())
            ->setCookTimeMinutes($source->getCookTimeMinutes())
            ->setEstimatedCost($source->getEstimatedCost())
            ->setMainIngredient($source->getMainIngredient())
            ->setSeasonality($source->getSeasonality())
            ->setImageUrl($source->getImageUrl())
            ->setDifficulty($source->getDifficulty())
            ->setCaloriesPerPortion($source->getCaloriesPerPortion())
            ->setSourceUrl($source->getSourceUrl())
            ->setPortionPriceLabel($source->getPortionPriceLabel())
            ->setSteps($source->getSteps());

        foreach ($target->getRecipeIngredients()->toArray() as $line) {
            $target->removeRecipeIngredient($line);
        }
        foreach ($source->getRecipeIngredients() as $line) {
            $target->addRecipeIngredient($line);
        }
    }

    /**
     * @param list<string|null>|false $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string|null> $headerRow
     *
     * @return array<string, int>
     */
    private function buildColumnIndex(array $headerRow): array
    {
        $index = [];
        foreach ($headerRow as $i => $rawName) {
            $key = $this->normalizeHeaderKey((string) $rawName);
            if ($key === '') {
                continue;
            }
            $canonical = self::HEADER_MAP[$key] ?? $key;
            $index[$canonical] = $i;
        }

        return $index;
    }

    private function normalizeHeaderKey(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ù', 'û', 'ô', 'î', 'ï', 'ç'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'u', 'u', 'o', 'i', 'i', 'c'],
            $s
        );
        $s = preg_replace('/\s+/', '_', $s) ?? $s;

        return $s;
    }

    /**
     * @param list<string|null> $row
     */
    private function buildRecipeFromRow(array $row, array $columnIndex, int $lineNumber): Recipe
    {
        $name = trim($this->cell($row, $columnIndex, 'name'));
        if ($name === '') {
            throw new \InvalidArgumentException(sprintf('Ligne %d : le nom est obligatoire.', $lineNumber));
        }
        if (mb_strlen($name) > 160) {
            throw new \InvalidArgumentException(sprintf('Ligne %d : nom trop long (max 160 caracteres).', $lineNumber));
        }

        $costStr = trim($this->cell($row, $columnIndex, 'estimated_cost'));
        $cost = $this->parseDecimal($costStr);
        if ($cost === null) {
            throw new \InvalidArgumentException(sprintf('Ligne %d : cout estime invalide ou vide.', $lineNumber));
        }

        $main = trim($this->cell($row, $columnIndex, 'main_ingredient'));
        $allowedMain = array_values(RecipeChoices::mainIngredients());
        if (!in_array($main, $allowedMain, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Ligne %d : aliment principal invalide (%s). Valeurs : %s.',
                $lineNumber,
                $main,
                implode(', ', $allowedMain)
            ));
        }

        $prep = $this->parseOptionalInt($this->cell($row, $columnIndex, 'preparation_minutes'), 30);
        if ($prep < 1) {
            throw new \InvalidArgumentException(sprintf('Ligne %d : temps de preparation doit etre >= 1.', $lineNumber));
        }

        $cook = $this->parseOptionalInt($this->cell($row, $columnIndex, 'cook_minutes'), 0);
        if ($cook < 0) {
            throw new \InvalidArgumentException(sprintf('Ligne %d : temps de cuisson invalide.', $lineNumber));
        }

        $season = trim($this->cell($row, $columnIndex, 'seasonality'));
        $allowedSeason = array_values(RecipeChoices::seasonality());
        if ($season === '') {
            $season = 'toute_annee';
        } elseif (!in_array($season, $allowedSeason, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Ligne %d : saisonnalite invalide (%s).',
                $lineNumber,
                $season
            ));
        }

        $diffRaw = trim($this->cell($row, $columnIndex, 'difficulty'));
        $difficulty = $diffRaw === '' ? null : $diffRaw;
        if ($difficulty !== null) {
            $allowedDiff = array_values(RecipeChoices::difficulties());
            if (!in_array($difficulty, $allowedDiff, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Ligne %d : difficulte invalide (%s).',
                    $lineNumber,
                    $difficulty
                ));
            }
        }

        $calories = null;
        $calStr = trim($this->cell($row, $columnIndex, 'calories'));
        if ($calStr !== '') {
            if (!ctype_digit($calStr)) {
                throw new \InvalidArgumentException(sprintf('Ligne %d : calories invalides.', $lineNumber));
            }
            $calories = (int) $calStr;
        }

        $imageUrl = $this->nullableTrimmed($this->cell($row, $columnIndex, 'image_url'), 512);
        $sourceUrl = $this->nullableTrimmed($this->cell($row, $columnIndex, 'source_url'), 2048);
        $portionLabel = $this->nullableTrimmed($this->cell($row, $columnIndex, 'portion_price_label'), 160);

        $recipe = (new Recipe())
            ->setName($name)
            ->setPreparationTimeMinutes($prep)
            ->setCookTimeMinutes($cook)
            ->setEstimatedCost($cost)
            ->setMainIngredient($main)
            ->setSeasonality($season)
            ->setDifficulty($difficulty)
            ->setCaloriesPerPortion($calories)
            ->setImageUrl($imageUrl)
            ->setSourceUrl($sourceUrl)
            ->setPortionPriceLabel($portionLabel);

        $ingCell = $this->cell($row, $columnIndex, 'ingredients');
        foreach ($this->parseIngredientsCell($ingCell, $lineNumber) as $item) {
            $ri = (new RecipeIngredient())
                ->setIngredientName($item['name'])
                ->setCategory('autre')
                ->setQuantity($item['quantity'])
                ->setUnit($item['unit']);
            $recipe->addRecipeIngredient($ri);
        }

        return $recipe;
    }

    /**
     * @param list<string|null> $row
     */
    private function cell(array $row, array $columnIndex, string $key): string
    {
        if (!isset($columnIndex[$key])) {
            return '';
        }
        $i = $columnIndex[$key];

        return trim((string) ($row[$i] ?? ''));
    }

    private function parseDecimal(string $s): ?string
    {
        $s = trim(str_replace(["\xc2\xa0", ' '], '', $s));
        if ($s === '') {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return null;
        }

        return number_format((float) $s, 2, '.', '');
    }

    private function parseOptionalInt(string $s, int $default): int
    {
        $s = trim($s);
        if ($s === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $s)) {
            return $default;
        }

        return (int) $s;
    }

    private function nullableTrimmed(string $value, int $maxLen): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLen) {
            return mb_substr($value, 0, $maxLen);
        }

        return $value;
    }

    /**
     * @return list<array{name: string, quantity: string, unit: string}>
     */
    private function parseIngredientsCell(string $cell, int $lineNumber): array
    {
        $cell = trim($cell);
        if ($cell === '') {
            return [];
        }

        $parts = array_map('trim', explode(';', $cell));
        $out = [];
        $n = 0;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            ++$n;
            $segments = explode('|', $part, 3);
            if (count($segments) < 3) {
                throw new \InvalidArgumentException(sprintf(
                    'Ligne %d : ingredient #%d — format attendu quantite|unite|libelle (ex. 200|g|farine).',
                    $lineNumber,
                    $n
                ));
            }
            [$qStr, $unit, $label] = $segments;
            $label = trim($label);
            $unit = trim($unit);
            if ($label === '') {
                throw new \InvalidArgumentException(sprintf('Ligne %d : ingredient #%d sans libelle.', $lineNumber, $n));
            }
            if (mb_strlen($label) > 180) {
                $label = mb_substr($label, 0, 180);
            }
            if ($unit === '') {
                $unit = 'pce';
            }
            if (mb_strlen($unit) > 20) {
                $unit = mb_substr($unit, 0, 20);
            }
            $qStr = trim(str_replace(',', '.', $qStr));
            if ($qStr === '' || !is_numeric($qStr) || (float) $qStr <= 0) {
                throw new \InvalidArgumentException(sprintf(
                    'Ligne %d : ingredient #%d — quantite positive attendue (%s).',
                    $lineNumber,
                    $n,
                    trim($segments[0])
                ));
            }
            $out[] = [
                'name' => $label,
                'quantity' => number_format((float) $qStr, 2, '.', ''),
                'unit' => $unit,
            ];
        }

        return $out;
    }

    private function linkIngredientEntity(RecipeIngredient $recipeIngredient, EntityManagerInterface $entityManager): void
    {
        $name = trim($recipeIngredient->getIngredientName());
        if ($name === '') {
            return;
        }

        $ingredient = $entityManager->getRepository(Ingredient::class)->findOneBy(['name' => $name]);
        if ($ingredient === null) {
            $ingredient = (new Ingredient())
                ->setName($name)
                ->setDefaultUnit($recipeIngredient->getUnit() !== '' ? $recipeIngredient->getUnit() : 'g');
            $entityManager->persist($ingredient);
        }

        if ($recipeIngredient->getCategory() === '' || $recipeIngredient->getCategory() === 'autre') {
            $recipeIngredient->setCategory($ingredient->getCategory());
        } elseif ($ingredient->getCategory() === 'autre') {
            $ingredient->setCategory($recipeIngredient->getCategory());
        }
        $recipeIngredient->setIngredient($ingredient);
    }
}
