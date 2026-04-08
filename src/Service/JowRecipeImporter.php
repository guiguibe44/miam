<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class JowRecipeImporter
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array{
     *   name:string,
     *   sourceUrl:string,
     *   imageUrl:?string,
     *   preparationTimeMinutes:int,
     *   cookTimeMinutes:int,
     *   estimatedCost:string,
     *   portionPriceLabel:?string,
     *   difficulty:?string,
     *   caloriesPerPortion:?int,
     *   mainIngredient:string,
     *   steps:?string,
     *   ingredients:list<array{name:string,quantity:string,unit:string}>
     * }
     */
    public function importFromUrl(string $url): array
    {
        $sourceUrl = $this->normalizeSourceUrl($url);

        if (!preg_match('#^https://jow\.fr/recipes/#', $sourceUrl)) {
            throw new \InvalidArgumentException('L URL doit etre une recette jow.fr.');
        }

        $response = $this->httpClient->request('GET', $sourceUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; MiamBot/1.0)',
            ],
            'timeout' => 20,
        ]);

        $html = $response->getContent();

        $nextRecipe = $this->extractNextDataRecipe($html);
        if (is_array($nextRecipe)) {
            return $this->mapFromNextData($nextRecipe, $sourceUrl);
        }

        return $this->mapFromJsonLd($html, $sourceUrl);
    }

    /**
     * @param array<string, mixed> $recipe
     * @return array{
     *   name:string,
     *   sourceUrl:string,
     *   imageUrl:?string,
     *   preparationTimeMinutes:int,
     *   cookTimeMinutes:int,
     *   estimatedCost:string,
     *   portionPriceLabel:?string,
     *   difficulty:?string,
     *   caloriesPerPortion:?int,
     *   mainIngredient:string,
     *   steps:?string,
     *   ingredients:list<array{name:string,quantity:string,unit:string}>
     * }
     */
    private function mapFromNextData(array $recipe, string $sourceUrl): array
    {
        $name = trim((string) ($recipe['title'] ?? 'Recette Jow'));
        $imageUrl = $this->nullableNonEmptyString($recipe['imageUrlHD'] ?? null)
            ?? $this->nullableNonEmptyString($recipe['imageUrl'] ?? null)
            ?? $this->nullableNonEmptyString($recipe['thumbnailUrl'] ?? null);

        $preparationTime = max(1, (int) ($recipe['preparationTime'] ?? 1));
        $cookTime = max(0, (int) ($recipe['cookingTime'] ?? 0));

        $priceTag = $recipe['pricePerPortionTag'] ?? null;
        $portionPriceLabel = null;
        $priceLevel = null;
        if (is_array($priceTag)) {
            $portionPriceLabel = $this->nullableNonEmptyString($priceTag['label'] ?? null);
            if (isset($priceTag['level']) && is_numeric($priceTag['level'])) {
                $priceLevel = (int) $priceTag['level'];
            }
        }

        $difficulty = $this->mapDifficulty(isset($recipe['difficulty']) ? (int) $recipe['difficulty'] : null);
        $caloriesPerPortion = $this->extractCaloriesKcal($recipe['nutritionalFacts'] ?? null);

        $covers = max(1, (int) ($recipe['coversCount'] ?? 1));
        $ingredients = [];
        $constituents = $recipe['constituents'] ?? null;
        if (is_array($constituents)) {
            foreach ($constituents as $constituent) {
                if (!is_array($constituent)) {
                    continue;
                }

                $parsed = $this->constituentToIngredientLine($constituent, $covers);
                if ($parsed !== null) {
                    $ingredients[] = $parsed;
                }
            }
        }

        if ($ingredients === []) {
            throw new \RuntimeException('Aucun ingredient exploitable (constituents) dans les donnees Jow.');
        }

        $estimatedCost = $this->maxEuroPricePerPortion($portionPriceLabel, $priceLevel);
        $steps = $this->extractStepsFromNextData($recipe);

        return [
            'name' => $name,
            'sourceUrl' => $sourceUrl,
            'imageUrl' => $imageUrl,
            'preparationTimeMinutes' => $preparationTime,
            'cookTimeMinutes' => $cookTime,
            'estimatedCost' => $estimatedCost,
            'portionPriceLabel' => $portionPriceLabel,
            'difficulty' => $difficulty,
            'caloriesPerPortion' => $caloriesPerPortion,
            'mainIngredient' => $this->detectMainIngredient($name, $ingredients),
            'steps' => $steps,
            'ingredients' => $ingredients,
        ];
    }

    /**
     * @return array{
     *   name:string,
     *   sourceUrl:string,
     *   imageUrl:?string,
     *   preparationTimeMinutes:int,
     *   cookTimeMinutes:int,
     *   estimatedCost:string,
     *   portionPriceLabel:?string,
     *   difficulty:?string,
     *   caloriesPerPortion:?int,
     *   mainIngredient:string,
     *   steps:?string,
     *   ingredients:list<array{name:string,quantity:string,unit:string}>
     * }
     */
    private function mapFromJsonLd(string $html, string $sourceUrl): array
    {
        $recipeData = $this->extractRecipeJsonLd($html);
        if ($recipeData === null) {
            throw new \RuntimeException('Impossible de lire les donnees de recette sur cette page Jow.');
        }

        $name = trim((string) ($recipeData['name'] ?? 'Recette Jow'));
        $imageUrl = $this->extractImageUrl($recipeData);
        $prep = max(1, $this->durationToMinutes((string) ($recipeData['prepTime'] ?? 'PT1M')));
        $cook = max(0, $this->durationToMinutes((string) ($recipeData['cookTime'] ?? 'PT0M')));

        $nutrition = $recipeData['nutrition'] ?? null;
        $caloriesPerPortion = null;
        if (is_array($nutrition) && isset($nutrition['calories'])) {
            $caloriesPerPortion = $this->parseCaloriesString((string) $nutrition['calories']);
        }

        $difficulty = $this->extractDifficultyFromHtml($html);

        $ingredients = [];
        $ingredientLines = $recipeData['recipeIngredient'] ?? [];
        if (is_array($ingredientLines)) {
            foreach ($ingredientLines as $line) {
                $parsed = $this->parseIngredientLine((string) $line);
                if ($parsed !== null) {
                    $ingredients[] = $parsed;
                }
            }
        }

        if ($ingredients === []) {
            throw new \RuntimeException('Aucun ingredient exploitable trouve dans la recette Jow.');
        }

        $priceLevel = $this->detectPriceLevelFromHtml($html);
        $portionPriceLabel = $this->priceLabelFromLevel($priceLevel);
        $steps = $this->extractStepsFromJsonLd($recipeData);

        return [
            'name' => $name,
            'sourceUrl' => $sourceUrl,
            'imageUrl' => $imageUrl,
            'preparationTimeMinutes' => $prep,
            'cookTimeMinutes' => $cook,
            'estimatedCost' => $this->maxEuroPricePerPortion($portionPriceLabel, $priceLevel),
            'portionPriceLabel' => $portionPriceLabel,
            'difficulty' => $difficulty,
            'caloriesPerPortion' => $caloriesPerPortion,
            'mainIngredient' => $this->detectMainIngredient($name, $ingredients),
            'steps' => $steps,
            'ingredients' => $ingredients,
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function extractStepsFromNextData(array $recipe): ?string
    {
        $candidates = [
            $recipe['steps'] ?? null,
            $recipe['preparationSteps'] ?? null,
            $recipe['instructions'] ?? null,
            $recipe['directions'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $steps = $this->stepsFromMixed($candidate);
            if ($steps !== null) {
                return $steps;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $recipeData
     */
    private function extractStepsFromJsonLd(array $recipeData): ?string
    {
        return $this->stepsFromMixed($recipeData['recipeInstructions'] ?? null);
    }

    private function stepsFromMixed(mixed $value): ?string
    {
        if (is_string($value)) {
            $clean = trim($value);
            if ($clean === '') {
                return null;
            }

            return $clean;
        }

        if (!is_array($value)) {
            return null;
        }

        $lines = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $line = trim($item);
            } elseif (is_array($item)) {
                $line = trim((string) ($item['text'] ?? $item['description'] ?? $item['name'] ?? $item['label'] ?? ''));
            } else {
                $line = '';
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return null;
        }

        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = sprintf("%d. %s", $i + 1, $line);
        }

        return implode("\n", $out);
    }

    private function normalizeSourceUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('URL invalide.');
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $path = $parts['path'];
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return sprintf('%s://%s%s%s', $scheme, $host, $path, $query);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractNextDataRecipe(string $html): ?array
    {
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $matches)) {
            return null;
        }

        $decoded = json_decode(html_entity_decode($matches[1]), true);
        if (!is_array($decoded)) {
            return null;
        }

        $recipe = $decoded['props']['pageProps']['recipe'] ?? null;

        return is_array($recipe) ? $recipe : null;
    }

    /**
     * @param mixed $nutritionalFacts
     */
    private function extractCaloriesKcal($nutritionalFacts): ?int
    {
        if (!is_array($nutritionalFacts)) {
            return null;
        }

        foreach ($nutritionalFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }

            $id = strtoupper((string) ($fact['id'] ?? ''));
            if ($id === 'ENERC') {
                return (int) round((float) ($fact['amount'] ?? 0));
            }

            if (isset($fact['label']) && strcasecmp((string) $fact['label'], 'Calories') === 0) {
                return (int) round((float) ($fact['amount'] ?? 0));
            }
        }

        return null;
    }

    private function mapDifficulty(?int $level): ?string
    {
        if ($level === null || $level <= 0) {
            return null;
        }

        return match ($level) {
            1 => 'Très facile',
            2 => 'Facile',
            3 => 'Moyen',
            4 => 'Difficile',
            default => 'Niveau '.$level,
        };
    }

    /**
     * Borne haute numerique (EUR / portion) pour alimenter les filtres budget.
     */
    private function maxEuroPricePerPortion(?string $label, ?int $level): string
    {
        $label = $label !== null ? trim($label) : '';
        if ($label !== '') {
            if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:€|EUR)/iu', $label, $matches)) {
                $values = [];
                foreach ($matches[1] as $raw) {
                    $values[] = (float) str_replace(',', '.', $raw);
                }
                if ($values !== []) {
                    return number_format(max($values), 2, '.', '');
                }
            }

            if (preg_match('/moins\s+de\s*(\d+(?:[.,]\d+)?)\s*(?:€|EUR)/iu', $label, $m)) {
                return number_format((float) str_replace(',', '.', $m[1]), 2, '.', '');
            }

            if (preg_match('/plus\s+de\s*(\d+(?:[.,]\d+)?)\s*(?:€|EUR)/iu', $label, $m)) {
                $floor = (float) str_replace(',', '.', $m[1]);

                return number_format(max($floor + 1.0, 6.0), 2, '.', '');
            }
        }

        return match ($level) {
            1 => '2.00',
            2 => '4.00',
            3 => '6.50',
            default => '4.00',
        };
    }

    private function priceLabelFromLevel(?int $level): ?string
    {
        return match ($level) {
            1 => 'Moins de 2 € / portion (indicatif Jow)',
            2 => 'Entre 2 € et 4 € / portion (indicatif Jow)',
            3 => 'Plus de 4 € / portion (indicatif Jow)',
            default => null,
        };
    }

    /**
     * @return int|null 1..3
     */
    private function detectPriceLevelFromHtml(string $html): ?int
    {
        if (preg_match('/pricePerPortionTag&quot;:\{&quot;level&quot;:(\d+)/', $html, $m)) {
            return (int) $m[1];
        }

        if (!str_contains($html, 'fSXQvW">€')) {
            return null;
        }

        $count = preg_match_all('/fSXQvW">€/u', $html, $m);
        if ($count === 1) {
            return 1;
        }

        if ($count === 2) {
            return 2;
        }

        if ($count >= 3) {
            return 3;
        }

        return null;
    }

    private function extractDifficultyFromHtml(string $html): ?string
    {
        if (preg_match('/Difficulté &quot;([^&]+)&quot;/u', $html, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/title="Difficulté &quot;([^"]+)"/u', $html, $m)) {
            return trim(html_entity_decode($m[1]));
        }

        return null;
    }

    private function parseCaloriesString(string $value): ?int
    {
        if (preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $constituent
     * @return array{name:string,quantity:string,unit:string}|null
     */
    private function constituentToIngredientLine(array $constituent, int $covers): ?array
    {
        $name = trim((string) ($constituent['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $qpc = (float) ($constituent['quantityPerCover'] ?? 0);
        $total = $qpc * max(1, $covers);
        $unit = $constituent['unit'] ?? null;
        $unitName = is_array($unit) ? (string) ($unit['name'] ?? '') : '';

        if (str_contains($unitName, 'Kilogramme')) {
            return [
                'name' => $name,
                'quantity' => number_format($total * 1000, 2, '.', ''),
                'unit' => 'g',
            ];
        }

        if (str_contains($unitName, 'Gramme')) {
            return [
                'name' => $name,
                'quantity' => number_format($total, 2, '.', ''),
                'unit' => 'g',
            ];
        }

        if (str_contains($unitName, 'Litre')) {
            return [
                'name' => $name,
                'quantity' => number_format($total * 1000, 2, '.', ''),
                'unit' => 'ml',
            ];
        }

        if (str_contains($unitName, 'Pièce') || str_contains($unitName, 'Piece')) {
            return [
                'name' => $name,
                'quantity' => number_format($total, 2, '.', ''),
                'unit' => 'piece',
            ];
        }

        return [
            'name' => $name,
            'quantity' => number_format($total, 2, '.', ''),
            'unit' => 'piece',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractRecipeJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $rawJson) {
            $decoded = json_decode(html_entity_decode($rawJson), true);
            if (!is_array($decoded)) {
                continue;
            }

            $candidate = $this->findRecipeNode($decoded);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function findRecipeNode(array $node): ?array
    {
        $type = $node['@type'] ?? null;
        if (is_string($type) && strtolower($type) === 'recipe') {
            return $node;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) {
                    $found = $this->findRecipeNode($child);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $recipeData
     */
    private function extractImageUrl(array $recipeData): ?string
    {
        $image = $recipeData['image'] ?? null;

        if (is_string($image) && $image !== '') {
            return $image;
        }

        if (is_array($image) && $image !== []) {
            $first = $image[0] ?? null;
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }

    private function durationToMinutes(string $duration): int
    {
        if ($duration === '') {
            return 0;
        }

        try {
            $interval = new \DateInterval($duration);

            return ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{name:string,quantity:string,unit:string}|null
     */
    private function parseIngredientLine(string $line): ?array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $line) ?? '');
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^(?<qty>\d+(?:[.,]\d+)?)\s*(?<unit>kg|g|mg|l|ml|cl|piece|pieces|pi[eè]ce|pi[eè]ces)?\s+(?<name>.+)$/iu', $clean, $m)) {
            $qty = str_replace(',', '.', (string) $m['qty']);
            $unit = strtolower(trim((string) ($m['unit'] ?? '')));
            $unit = $unit === '' ? 'piece' : $unit;

            return [
                'name' => trim((string) $m['name']),
                'quantity' => number_format((float) $qty, 2, '.', ''),
                'unit' => $unit,
            ];
        }

        return [
            'name' => $clean,
            'quantity' => '1.00',
            'unit' => 'piece',
        ];
    }

    private function nullableNonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param list<array{name:string,quantity:string,unit:string}> $ingredients
     */
    private function detectMainIngredient(string $name, array $ingredients): string
    {
        $haystack = mb_strtolower($name.' '.implode(' ', array_map(static fn (array $item): string => $item['name'], $ingredients)));

        return match (true) {
            str_contains($haystack, 'poulet') => 'poulet',
            str_contains($haystack, 'porc'),
            str_contains($haystack, 'jambon'),
            str_contains($haystack, 'lardon'),
            str_contains($haystack, 'bacon'),
            str_contains($haystack, 'saucisse'),
            str_contains($haystack, 'chorizo') => 'porc',
            str_contains($haystack, 'poisson'),
            str_contains($haystack, 'saumon') => 'poisson',
            str_contains($haystack, 'boeuf'),
            str_contains($haystack, 'bœuf') => 'boeuf',
            str_contains($haystack, 'pates'),
            str_contains($haystack, 'pâtes') => 'pates',
            default => 'vegetarien',
        };
    }
}
