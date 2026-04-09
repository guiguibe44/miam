<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Import d'une fiche recette depuis une URL publique 750g.com (ex. …-r37518.htm).
 */
final class RecipeWeb750gImporter
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
        $sourceUrl = $this->normalize750gRecipeUrl($url);

        $response = $this->httpClient->request('GET', $sourceUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.7',
            ],
            'timeout' => 25,
        ]);

        $html = $response->getContent();

        $jsonLd = $this->extractRecipeJsonLd($html);
        $ingredients = [];
        $name = null;
        $imageUrl = null;
        $prep = null;
        $cook = null;
        $difficulty = null;
        $calories = null;
        $steps = null;

        if ($jsonLd !== null) {
            $name = isset($jsonLd['name']) ? trim((string) $jsonLd['name']) : null;
            $imageUrl = $this->extractImageUrl($jsonLd);
            $prep = $this->optionalMinutesFromIso((string) ($jsonLd['prepTime'] ?? ''));
            $cook = $this->optionalMinutesFromIso((string) ($jsonLd['cookTime'] ?? ''));
            $total = $this->optionalMinutesFromIso((string) ($jsonLd['totalTime'] ?? ''));
            if (($prep === null || $prep === 0) && ($cook === null || $cook === 0) && $total !== null && $total > 0) {
                $prep = max(1, $total);
            } else {
                $prep = $prep ?? 1;
                $cook = $cook ?? 0;
            }

            $nutrition = $jsonLd['nutrition'] ?? null;
            if (is_array($nutrition) && isset($nutrition['calories'])) {
                $calories = $this->parseCaloriesString((string) $nutrition['calories']);
            }

            $difficulty = $this->nullableString($jsonLd['difficulty'] ?? null);

            $rawIngredients = $jsonLd['recipeIngredient'] ?? [];
            if (is_array($rawIngredients)) {
                foreach ($rawIngredients as $line) {
                    $parsed = $this->parseIngredientLine((string) $line);
                    if ($parsed !== null) {
                        $ingredients[] = $parsed;
                    }
                }
            }

            $steps = $this->stepsFromJsonLdInstructions($jsonLd['recipeInstructions'] ?? null);
        }

        if ($name === null || $name === '') {
            $name = $this->extractFirstH1Text($html) ?? 'Recette 750g';
        }

        if ($imageUrl === null || trim((string) $imageUrl) === '') {
            $imageUrl = $this->extractOgImageFromHtml($html);
        }

        if ($ingredients === []) {
            $ingredients = $this->extractIngredientsFromHtml($html);
        }

        if ($steps === null || trim((string) $steps) === '') {
            $steps = $this->extractStepsFromHtml($html);
        }

        if ($prep === null) {
            $prep = $this->extractMinutesAfterLabel($html, 'Préparation');
        }
        if ($cook === null || $cook === 0) {
            $cook = $this->extractMinutesAfterLabel($html, 'Cuisson') ?? 0;
        }
        if (($prep === null || $prep < 1) && $cook >= 0) {
            $totalChip = $this->extractLeadingMinutesFromHtml($html);
            if ($totalChip !== null && $totalChip > 0) {
                $prep = max(1, $totalChip - $cook);
            }
        }
        $prep = max(1, (int) ($prep ?? 1));
        $cook = max(0, (int) ($cook ?? 0));

        if ($difficulty === null || $difficulty === '') {
            $difficulty = $this->extractDifficultyChip($html);
        }

        $portionPriceLabel = $this->extractBudgetLabel($html);
        $estimatedCost = $this->budgetLabelToEstimatedCost($portionPriceLabel);

        if ($ingredients === []) {
            throw new \RuntimeException('Aucun ingrédient exploitable sur cette page 750g.');
        }

        return [
            'name' => $name,
            'sourceUrl' => $sourceUrl,
            'imageUrl' => $imageUrl,
            'preparationTimeMinutes' => $prep,
            'cookTimeMinutes' => $cook,
            'estimatedCost' => $estimatedCost,
            'portionPriceLabel' => $portionPriceLabel,
            'difficulty' => $difficulty,
            'caloriesPerPortion' => $calories,
            'mainIngredient' => $this->detectMainIngredient($name, $ingredients),
            'steps' => $steps,
            'ingredients' => $ingredients,
        ];
    }

    private function normalize750gRecipeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('URL invalide.');
        }

        $host = strtolower($parts['host']);
        if ($host !== '750g.com' && $host !== 'www.750g.com') {
            throw new \InvalidArgumentException('L’URL doit être une recette www.750g.com.');
        }

        $path = $parts['path'];
        if (!preg_match('#-r\d+\.htm$#i', $path)) {
            throw new \InvalidArgumentException('Format attendu : https://www.750g.com/…-r12345.htm');
        }

        $path = $path !== '' ? $path : '/';

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return sprintf('https://www.750g.com%s%s', $path, $query);
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
            if (isset($image['url']) && is_string($image['url']) && $image['url'] !== '') {
                return $image['url'];
            }
            $first = $image[0] ?? null;
            if (is_string($first) && $first !== '') {
                return $first;
            }
            if (is_array($first)) {
                $u = $first['url'] ?? null;

                return is_string($u) && $u !== '' ? $u : null;
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

    private function optionalMinutesFromIso(string $duration): ?int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return null;
        }
        try {
            $interval = new \DateInterval($duration);

            return ($interval->d * 24 * 60) + ($interval->h * 60) + $interval->i + (int) round($interval->s / 60);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseCaloriesString(string $value): ?int
    {
        if (preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractRecipeJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $rawJson) {
            $normalized = $this->decodeJsonHtmlEntities($rawJson);
            $decoded = json_decode($normalized, true);
            if (!is_array($decoded)) {
                $stripped = $this->stripJsonLdRecipeInstructionsValue($normalized);
                if ($stripped !== null) {
                    $decoded = json_decode($stripped, true);
                }
            }
            if (!is_array($decoded)) {
                $decoded = $this->parse750gRecipeFromBrokenJsonLd($normalized);
            }
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

    private function decodeJsonHtmlEntities(string $raw): string
    {
        return html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Le JSON-LD 750g contient souvent des sauts de ligne bruts dans recipeInstructions[].text,
     * ce qui casse json_decode. On retire la propriété entière (ingrédients et métadonnées restent valides).
     */
    private function stripJsonLdRecipeInstructionsValue(string $json): ?string
    {
        $key = '"recipeInstructions"';
        $keyPos = strpos($json, $key);
        if ($keyPos === false) {
            return null;
        }
        $prefix = substr($json, 0, $keyPos);
        $commaBefore = strrpos($prefix, ',');
        if ($commaBefore === false) {
            return null;
        }
        $bracketStart = strpos($json, '[', $keyPos + strlen($key));
        if ($bracketStart === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($json);
        for ($i = $bracketStart; $i < $len; ++$i) {
            $ch = $json[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;

                continue;
            }
            if ($ch === '[') {
                ++$depth;
            } elseif ($ch === ']') {
                --$depth;
                if ($depth === 0) {
                    $removeLen = $i - $commaBefore + 1;

                    return substr_replace($json, '', $commaBefore, $removeLen);
                }
            }
        }

        return null;
    }

    /**
     * Extraction minimale si le bloc JSON reste invalide (recipeIngredient est généralement bien formé).
     *
     * @return array<string, mixed>|null
     */
    private function parse750gRecipeFromBrokenJsonLd(string $raw): ?array
    {
        if (!preg_match('/"recipeIngredient"\s*:\s*(\[[\s\S]*?\])\s*,/u', $raw, $m)) {
            return null;
        }
        $ingredients = json_decode($m[1], true);
        if (!is_array($ingredients) || $ingredients === []) {
            return null;
        }

        $out = [
            '@type' => 'Recipe',
            'recipeIngredient' => $ingredients,
        ];
        if (preg_match('/"name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $n)) {
            $out['name'] = stripcslashes($n[1]);
        }
        foreach (['prepTime', 'cookTime', 'totalTime'] as $k) {
            if (preg_match('/"'.$k.'"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $t)) {
                $out[$k] = stripcslashes($t[1]);
            }
        }

        return $out;
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
        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && strtolower($t) === 'recipe') {
                    return $node;
                }
            }
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

    private function stepsFromJsonLdInstructions(mixed $value): ?string
    {
        if (is_string($value)) {
            $clean = trim($value);

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
     * @return list<array{name:string,quantity:string,unit:string}>
     */
    private function extractIngredientsFromHtml(string $html): array
    {
        $dom = $this->loadHtml($html);
        if ($dom === null) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $out = [];
        $nodes = $xpath->query('//*[@itemprop="recipeIngredient"]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
                $parsed = $this->parseIngredientLine($text);
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
            }
        }
        if ($out !== []) {
            return $out;
        }

        foreach ($xpath->query('//*[self::h2 or self::h3][contains(., "Ingrédient") or contains(., "Ingredient")]') as $heading) {
            $ul = $xpath->query('following-sibling::ul[1]', $heading)->item(0);
            if (!$ul instanceof \DOMElement) {
                continue;
            }
            foreach ($ul->getElementsByTagName('li') as $li) {
                $parsed = $this->parseIngredientLine(trim(preg_replace('/\s+/', ' ', $li->textContent) ?? ''));
                if ($parsed !== null) {
                    $out[] = $parsed;
                }
            }
            if ($out !== []) {
                break;
            }
        }

        return $out;
    }

    private function extractStepsFromHtml(string $html): ?string
    {
        $dom = $this->loadHtml($html);
        if ($dom === null) {
            return null;
        }
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//*[@itemprop="recipeInstructions"]');
        if ($nodes !== false && $nodes->length > 0) {
            $first = $nodes->item(0);
            $text = trim(preg_replace('/\s+/', ' ', $first?->textContent ?? '') ?? '');
            if ($text !== '') {
                return $this->normalizeStepsMultiline($text);
            }
        }

        $recipeSteps = $xpath->query('//li[contains(concat(" ", normalize-space(@class), " "), " recipe-steps-item ")]');
        if ($recipeSteps !== false && $recipeSteps->length > 0) {
            $lines = [];
            foreach ($recipeSteps as $li) {
                if (!$li instanceof \DOMElement) {
                    continue;
                }
                $posNode = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " recipe-steps-position ")]', $li)->item(0);
                $textNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " recipe-steps-text ")]', $li)->item(0);
                if (!$textNode instanceof \DOMElement) {
                    continue;
                }
                $num = $posNode !== null ? trim(preg_replace('/\s+/', ' ', $posNode->textContent) ?? '') : '';
                if ($num === '') {
                    $num = (string) (count($lines) + 1);
                }
                $chunk = trim(preg_replace('/\s+/', ' ', html_entity_decode($textNode->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if ($chunk !== '') {
                    $lines[] = sprintf('%s. %s', $num, $chunk);
                }
            }
            if ($lines !== []) {
                return implode("\n", $lines);
            }
        }

        foreach ($xpath->query('//*[self::h2 or self::h3][contains(., "Préparation") or contains(., "Preparation")]') as $heading) {
            $ol = $xpath->query('following-sibling::ol[1]', $heading)->item(0);
            if ($ol instanceof \DOMElement) {
                $lines = [];
                foreach ($ol->getElementsByTagName('li') as $i => $li) {
                    $chunk = trim(preg_replace('/\s+/', ' ', html_entity_decode($li->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                    if ($chunk !== '') {
                        $lines[] = sprintf('%d. %s', $i + 1, $chunk);
                    }
                }
                if ($lines !== []) {
                    return implode("\n", $lines);
                }
            }
        }

        return null;
    }

    private function normalizeStepsMultiline(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');
        $text = preg_replace('/\n\s*\n+/u', "\n", $text) ?? $text;

        return trim((string) $text);
    }

    private function loadHtml(string $html): ?\DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOENT | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $dom : null;
    }

    private function extractFirstH1Text(string $html): ?string
    {
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $text !== '' ? $text : null;
        }

        return null;
    }

    private function extractMinutesAfterLabel(string $html, string $label): ?int
    {
        $pattern = '/'.preg_quote($label, '/').'\s*:\s*[^0-9]*(\d+)\s*min/i';
        if (preg_match($pattern, $html, $m)) {
            return max(0, (int) $m[1]);
        }

        return null;
    }

    private function extractLeadingMinutesFromHtml(string $html): ?int
    {
        if (preg_match('/>(\d+)\s*min</', $html, $m)) {
            return max(0, (int) $m[1]);
        }

        return null;
    }

    private function extractDifficultyChip(string $html): ?string
    {
        if (preg_match('/>\s*(Très facile|Facile|Moyen|Difficile)\s*</iu', $html, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractBudgetLabel(string $html): ?string
    {
        if (preg_match('/Budget\s+(économique|mini|moyen|cher|maxi)/iu', $html, $m)) {
            return 'Budget '.mb_strtolower(trim($m[1]));
        }
        if (preg_match('/>\s*(Budget\s+[^<]{3,40})\s*</iu', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function budgetLabelToEstimatedCost(?string $label): string
    {
        $label = $label !== null ? mb_strtolower($label) : '';

        return match (true) {
            str_contains($label, 'économique'), str_contains($label, 'mini') => '2.00',
            str_contains($label, 'cher'), str_contains($label, 'maxi') => '6.50',
            default => '4.00',
        };
    }

    private function nullableString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v !== '' ? $v : null;
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
            str_contains($haystack, 'riz') => 'pates',
            default => 'vegetarien',
        };
    }
}
