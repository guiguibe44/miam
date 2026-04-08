<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extrait les URLs de fiches recettes depuis une page article du blog Jow (Webflow),
 * via les iframes d'embed et l'API JSON-LD des pages /embed/.
 */
class JowBlogRecipeLinkCollector
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return list<string> URLs https://jow.fr/recipes/... (sans doublon, ordre de parcours)
     */
    public function collectRecipeUrls(string $blogUrl): array
    {
        $normalizedBlog = $this->assertAllowedBlogUrl($blogUrl);

        $response = $this->httpClient->request('GET', $normalizedBlog, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; MiamFamilleBot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
            'timeout' => 25,
            'max_redirects' => 5,
        ]);

        $html = $response->getContent();
        $urls = [];

        foreach ($this->extractEmbedParamsFromHtml($html) as $params) {
            foreach ($this->fetchRecipeUrlsFromEmbedParams($params) as $recipeUrl) {
                $urls[$recipeUrl] = true;
            }
        }

        foreach ($this->extractDirectRecipeUrlsFromHtml($html) as $recipeUrl) {
            $urls[$recipeUrl] = true;
        }

        return array_keys($urls);
    }

    /**
     * @return list<string>
     */
    private function extractEmbedParamsFromHtml(string $html): array
    {
        $out = [];
        $parts = preg_split('/<iframe\b/i', $html) ?: [];
        foreach ($parts as $chunk) {
            if (!preg_match('/data-type\s*=\s*["\']recipes["\']/i', $chunk)) {
                continue;
            }
            if (preg_match('/data-params\s*=\s*["\']([^"\']+)["\']/i', $chunk, $m)) {
                $out[] = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function extractDirectRecipeUrlsFromHtml(string $html): array
    {
        if (!preg_match_all('#https://jow\.fr/recipes/[a-z0-9\-]+(?:\?[^\s"\'<>]+)?#i', $html, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $urls[$this->normalizeRecipePageUrl($url)] = true;
        }

        return array_keys($urls);
    }

    /**
     * @return list<string>
     */
    private function fetchRecipeUrlsFromEmbedParams(string $params): array
    {
        $params = trim($params);
        if ($params === '') {
            return [];
        }

        $embedUrl = 'https://jow.fr/embed/?'.http_build_query([
            'type' => 'recipes',
            'params' => $params,
            'card' => 'vertical',
        ]);

        $response = $this->httpClient->request('GET', $embedUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; MiamFamilleBot/1.0)',
            ],
            'timeout' => 25,
            'max_redirects' => 5,
        ]);

        return $this->parseRecipeUrlsFromEmbedHtml($response->getContent());
    }

    /**
     * @return list<string>
     */
    private function parseRecipeUrlsFromEmbedHtml(string $html): array
    {
        $urls = [];
        if (!preg_match_all('#<script[^>]*type="application/ld\\+json"[^>]*>(.*?)</script>#si', $html, $blocks)) {
            return [];
        }

        foreach ($blocks[1] as $raw) {
            $decoded = json_decode(html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5), true);
            if (!is_array($decoded)) {
                continue;
            }
            $urls = array_merge($urls, $this->recipeUrlsFromJsonLd($decoded));
        }

        $unique = [];
        foreach ($urls as $u) {
            $unique[$this->normalizeRecipePageUrl($u)] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function recipeUrlsFromJsonLd(array $node): array
    {
        $out = [];
        $type = $node['@type'] ?? null;
        if ($type === 'ItemList' && isset($node['itemListElement']) && is_array($node['itemListElement'])) {
            foreach ($node['itemListElement'] as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $item = $el['item'] ?? null;
                if (is_array($item)) {
                    $url = $item['url'] ?? null;
                    if (is_string($url) && str_contains($url, 'jow.fr/recipes/')) {
                        $out[] = $url;
                    }
                }
            }
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) {
                    $out = array_merge($out, $this->recipeUrlsFromJsonLd($child));
                }
            }
        }

        return $out;
    }

    private function normalizeRecipePageUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, \ENT_QUOTES | \ENT_HTML5));
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']) === 'https' ? 'https' : 'https';
        $host = strtolower($parts['host']);
        $path = $parts['path'];
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return sprintf('%s://%s%s%s', $scheme, $host, $path, $query);
    }

    private function assertAllowedBlogUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('URL d\'article blog invalide.');
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Seules les URLs http(s) sont acceptees.');
        }

        $host = strtolower($parts['host']);
        $allowedHosts = ['jow.fr', 'www.jow.fr', 'blog.jow.fr'];
        if (!in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException('L\'article doit etre sur le blog Jow (jow.fr ou blog.jow.fr).');
        }

        $path = $parts['path'];
        if (!str_contains($path, '/blog/')) {
            throw new \InvalidArgumentException('URL attendue : page d\'article du blog (chemin contenant /blog/).');
        }

        return $this->normalizeRecipePageUrl($url);
    }
}
