<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Import d'une recette depuis une page web contenant un bloc JSON-LD schema.org {@type Recipe}.
 * (ex. Marmiton, nombreux blogs et médias culinaires.)
 */
final class RecipeSchemaOrgImporter
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
        $sourceUrl = $this->normalizeHttpUrl($url);

        $response = $this->httpClient->request('GET', $sourceUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.7',
            ],
            'timeout' => 30,
        ]);

        $html = $response->getContent();

        $recipeLd = $this->extractBestRecipeJsonLd($html);
        if ($recipeLd === null) {
            throw new \RuntimeException(
                'Aucun bloc JSON-LD de type Recipe (schema.org) trouvé sur cette page. Vérifie que la recette est bien exposée en données structurées.'
            );
        }

        $name = trim((string) ($recipeLd['name'] ?? ''));
        if ($name === '') {
            $name = 'Recette importée';
        }

        $imageUrl = $this->extractImageUrl($recipeLd);
        if ($imageUrl === null || $imageUrl === '') {
            $imageUrl = $this->extractOgImageFromHtml($html);
        }

        $prep = $this->durationToMinutes((string) ($recipeLd['prepTime'] ?? ''));
        $cook = $this->durationToMinutes((string) ($recipeLd['cookTime'] ?? ''));
        $total = $this->durationToMinutes((string) ($recipeLd['totalTime'] ?? ''));
        if ($prep < 1 && $cook < 1 && $total > 0) {
            $prep = max(1, $total);
        } else {
            $prep = max(1, $prep);
            $cook = max(0, $cook);
        }

        $nutrition = $recipeLd['nutrition'] ?? null;
        $calories = null;
        if (is_array($nutrition) && isset($nutrition['calories'])) {
            $calories = $this->parseCaloriesString((string) $nutrition['calories']);
        }

        $difficulty = null;
        if (isset($recipeLd['difficulty'])) {
            $d = $recipeLd['difficulty'];
            $difficulty = is_string($d) ? trim($d) : (is_array($d) && isset($d['name']) ? trim((string) $d['name']) : null);
            if ($difficulty === '') {
                $difficulty = null;
            }
        }

        $ingredients = [];
        $rawLines = $recipeLd['recipeIngredient'] ?? [];
        if (is_array($rawLines)) {
            foreach ($rawLines as $line) {
                if (is_array($line)) {
                    $line = (string) ($line['text'] ?? $line['name'] ?? '');
                }
                $parsed = $this->parseIngredientLine((string) $line);
                if ($parsed !== null) {
                    $ingredients[] = $parsed;
                }
            }
        }

        if ($ingredients === []) {
            throw new \RuntimeException(
                'Le JSON-LD Recipe ne contient pas d’ingrédients exploitables (recipeIngredient vide ou illisible).'
            );
        }

        $steps = $this->stepsFromJsonLdInstructions($recipeLd['recipeInstructions'] ?? null);
        if ($steps === null || trim((string) $steps) === '') {
            $desc = isset($recipeLd['description']) ? trim(strip_tags((string) $recipeLd['description'])) : '';
            if ($desc !== '') {
                $steps = $desc;
            }
        }

        $portionPriceLabel = $this->buildPortionPriceLabel(
            $recipeLd,
            $this->normalizeAuthor($recipeLd['author'] ?? null)
        );

        return [
            'name' => $name,
            'sourceUrl' => $sourceUrl,
            'imageUrl' => $imageUrl,
            'preparationTimeMinutes' => $prep,
            'cookTimeMinutes' => $cook,
            'estimatedCost' => '4.00',
            'portionPriceLabel' => $portionPriceLabel,
            'difficulty' => $difficulty,
            'caloriesPerPortion' => $calories,
            'mainIngredient' => $this->detectMainIngredient($name, $ingredients),
            'steps' => $steps,
            'ingredients' => $ingredients,
        ];
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL invalide (http ou https attendu).');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Seuls les schémas http et https sont acceptés.');
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return sprintf('%s://%s%s%s', $scheme, $parts['host'], $path, $query);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractBestRecipeJsonLd(string $html): ?array
    {
        // type="application/ld+json" ou entités HTML (ex. Marmiton : application&#x2F;ld&#x2B;json)
        $ldJsonAttr = '(?:application/ld\+json|application&#(?:x2F|47);ld&#(?:x2B|43);json)';
        if (!preg_match_all(
            '~<script[^>]*\btype\s*=\s*(["\'])'.$ldJsonAttr.'\\1[^>]*>(.*?)</script>~si',
            $html,
            $matches
        )) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($matches[2] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($this->findAllRecipeNodes($decoded) as $node) {
                $ri = $node['recipeIngredient'] ?? [];
                $n = is_array($ri) ? count($ri) : 0;
                if ($n > $bestScore) {
                    $bestScore = $n;
                    $best = $node;
                }
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function findAllRecipeNodes(array $decoded): array
    {
        $out = [];
        if ($this->isRecipeType($decoded['@type'] ?? null)) {
            $out[] = $decoded;
        }
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            foreach ($decoded['@graph'] as $child) {
                if (is_array($child) && $this->isRecipeType($child['@type'] ?? null)) {
                    $out[] = $child;
                }
            }
        }

        return $out;
    }

    private function isRecipeType(mixed $type): bool
    {
        if (is_string($type)) {
            return strtolower($type) === 'recipe';
        }
        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && strtolower($t) === 'recipe') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $recipeLd
     */
    private function buildPortionPriceLabel(array $recipeLd, ?string $author): ?string
    {
        $parts = [];
        $yield = $recipeLd['recipeYield'] ?? null;
        if (is_string($yield) && trim($yield) !== '') {
            $parts[] = 'Portions : '.trim($yield);
        } elseif (is_array($yield)) {
            $y = implode(', ', array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $yield)));
            if ($y !== '') {
                $parts[] = 'Portions : '.$y;
            }
        }
        $cat = $recipeLd['recipeCategory'] ?? null;
        if (is_string($cat) && trim($cat) !== '') {
            $parts[] = trim($cat);
        }
        $cuisine = $recipeLd['recipeCuisine'] ?? null;
        if (is_string($cuisine) && trim($cuisine) !== '') {
            $parts[] = trim($cuisine);
        }
        if ($author !== null && $author !== '') {
            $parts[] = 'Auteur : '.$author;
        }
        if ($parts === []) {
            return null;
        }
        $label = implode(' · ', $parts);
        if (mb_strlen($label) > 160) {
            return mb_substr($label, 0, 157).'…';
        }

        return $label;
    }

    private function normalizeAuthor(mixed $author): ?string
    {
        if (is_string($author)) {
            $author = trim($author);

            return $author !== '' ? $author : null;
        }
        if (is_array($author)) {
            if (isset($author['name']) && is_string($author['name'])) {
                $n = trim($author['name']);

                return $n !== '' ? $n : null;
            }
            if (isset($author[0]) && is_array($author[0]) && isset($author[0]['name'])) {
                $n = trim((string) $author[0]['name']);

                return $n !== '' ? $n : null;
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
            if (isset($image['url'])) {
                $u = $image['url'];
                if (is_string($u) && $u !== '') {
                    return $u;
                }
            }
            foreach ($image as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }
                if (is_array($item) && isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
                    return $item['url'];
                }
            }
        }

        return null;
    }

    private function extractOgImageFromHtml(string $html): ?string
    {
        if (preg_match('/<meta\s+[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $url !== '' ? $url : null;
        }
        if (preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:image["\']/i', $html, $m)) {
            $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $url !== '' ? $url : null;
        }

        return null;
    }

    private function durationToMinutes(string $duration): int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return 0;
        }
        try {
            $interval = new \DateInterval($duration);

            return ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i + (int) round($interval->s / 60);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function parseCaloriesString(string $value): ?int
    {
        if (preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function stepsFromJsonLdInstructions(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $clean = trim(strip_tags($value));

            return $clean !== '' ? $clean : null;
        }
        if (!is_array($value)) {
            return null;
        }

        $lines = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $line = trim($item);
            } elseif (is_array($item)) {
                $types = $item['@type'] ?? null;
                $typeStr = is_string($types) ? $types : (is_array($types) ? implode(' ', $types) : '');
                if (str_contains(strtolower($typeStr), 'howtosection')) {
                    $nested = $this->stepsFromJsonLdInstructions($item['itemListElement'] ?? null);
                    if ($nested !== null) {
                        foreach (preg_split('/\n+/', $nested) ?: [] as $sub) {
                            $sub = trim($sub);
                            if ($sub !== '') {
                                $lines[] = preg_replace('/^\d+\.\s+/u', '', $sub) ?? $sub;
                            }
                        }
                    }

                    continue;
                }
                $line = trim((string) ($item['text'] ?? $item['name'] ?? $item['description'] ?? ''));
                if ($line === '' && isset($item['itemListElement']) && is_array($item['itemListElement'])) {
                    $nested = $this->stepsFromJsonLdInstructions($item['itemListElement']);
                    if ($nested !== null) {
                        $line = trim(preg_replace('/^\d+\.\s+/um', '', $nested) ?? '');
                    }
                }
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
            $out[] = sprintf('%d. %s', $i + 1, $line);
        }

        return implode("\n", $out);
    }

    /**
     * @return array{name:string,quantity:string,unit:string}|null
     */
    private function parseIngredientLine(string $line): ?array
    {
        $clean = trim(preg_replace('/\s+/', ' ', html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($clean === '') {
            return null;
        }

        $clean = preg_replace('/^[\x{2022}\-\*]\s*/u', '', $clean) ?? $clean;

        if (preg_match('/^(?<qty>\d+(?:[.,]\d+)?)\s*(?<unit>kg|g|mg|l|ml|cl|piece|pieces|pi[eè]ce|pi[eè]ces)?\s+(?<name>.+)$/iu', $clean, $m)) {
            $qty = str_replace(',', '.', (string) $m['qty']);
            $unit = strtolower(trim((string) ($m['unit'] ?? '')));
            $unit = $unit === '' ? 'piece' : $unit;
            if ($unit === 'kg') {
                return [
                    'name' => $this->normalizeIngredientName(trim((string) $m['name'])),
                    'quantity' => number_format((float) $qty * 1000, 2, '.', ''),
                    'unit' => 'g',
                ];
            }
            if ($unit === 'l') {
                return [
                    'name' => $this->normalizeIngredientName(trim((string) $m['name'])),
                    'quantity' => number_format((float) $qty * 1000, 2, '.', ''),
                    'unit' => 'ml',
                ];
            }

            return [
                'name' => $this->normalizeIngredientName(trim((string) $m['name'])),
                'quantity' => number_format((float) $qty, 2, '.', ''),
                'unit' => $unit,
            ];
        }

        return [
            'name' => $this->normalizeIngredientName($clean),
            'quantity' => '1.00',
            'unit' => 'piece',
        ];
    }

    private function normalizeIngredientName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/^de\s+/iu', '', $name) ?? $name;

        return trim($name);
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
            str_contains($haystack, 'saumon'),
            str_contains($haystack, 'thon') => 'poisson',
            str_contains($haystack, 'boeuf'),
            str_contains($haystack, 'bœuf') => 'boeuf',
            str_contains($haystack, 'pates'),
            str_contains($haystack, 'pâtes'),
            str_contains($haystack, 'riz'),
            str_contains($haystack, 'wrap'),
            str_contains($haystack, 'galette') => 'pates',
            default => 'vegetarien',
        };
    }
}
