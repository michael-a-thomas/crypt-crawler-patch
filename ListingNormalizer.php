<?php
declare(strict_types=1);

class ListingNormalizer
{
    private const STRIP_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'ref', 'referrer', 'fbclid', 'gclid', '_ga', 'mc_cid', 'mc_eid',
        'si', 'aff_id', 'affid', 'source', 'amp',
    ];

    public static function normalizeUrl(string $url, string $source): string
    {
        $url = trim($url);
        if (!$url) {
            return '';
        }
        if (!str_starts_with($url, 'http')) {
            $url = 'https:' . ltrim($url, ':');
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return $url;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host   = strtolower($parsed['host']);
        $path   = rtrim($parsed['path'] ?? '', '/');

        // Strip tracking query params
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            foreach (self::STRIP_PARAMS as $p) {
                unset($query[$p]);
            }
        }

        // Source-specific path cleanup — keep only the canonical item segment
        $path = match ($source) {
            'vinted'   => (preg_match('/\/items\/(\d+)/', $path, $m) ? '/items/' . $m[1] : $path),
            'depop'    => (preg_match('/\/products\/([^\/\?]+)/', $path, $m) ? '/products/' . $m[1] : $path),
            'poshmark' => (preg_match('/\/listing\/([^\/\?]+)/', $path, $m) ? '/listing/' . $m[1] : $path),
            'mercari'  => (preg_match('/\/item\/(m\d+)/', $path, $m) ? '/item/' . $m[1] : $path),
            default    => $path,
        };

        $normalized = $scheme . '://' . $host . $path;
        if ($query) {
            $normalized .= '?' . http_build_query($query);
        }
        return $normalized;
    }

    public static function extractItemId(string $url, string $source): ?string
    {
        return match ($source) {
            'vinted'   => (preg_match('/\/items\/(\d+)/', $url, $m) ? $m[1] : null),
            'depop'    => (preg_match('/\/products\/([a-zA-Z0-9\-_]+)/', $url, $m) ? $m[1] : null),
            'poshmark' => (preg_match('/--(\w{24})(?:[\/\?]|$)/', $url, $m) ? $m[1] : null),
            'mercari'  => (preg_match('/\/item\/(m\d+)/', $url, $m) ? $m[1] : null),
            default    => null,
        };
    }

    public static function makeCanonicalUrl(string $url, string $source): string
    {
        $normalized = self::normalizeUrl($url, $source);
        $itemId     = self::extractItemId($normalized, $source);
        if ($itemId) {
            return $source . ':' . $itemId;
        }
        // Fallback: use the normalized URL, or a hash if empty
        return $normalized ?: ($source . ':' . md5($url));
    }

    public static function deriveCategory(string $searchTerm): string
    {
        $term = strtoupper(trim($searchTerm));

        if (in_array($term, ['SB DUNK', 'NIKE SB DUNK', 'NIKE DUNK'], true)) {
            return 'All Dunks';
        }
        if (preg_match('/^(NIKE\s+)?AIR JORDAN(\s+\d+)?$/', $term)) {
            return 'All Jordans';
        }
        if (in_array($term, ['AIR MAX', 'NIKE AIR MAX'], true)) {
            return 'All Max';
        }
        return 'Other';
    }

    public static function shouldExclude(string $title, ?string $description, string $searchTerm): bool
    {
        $text = strtolower($title . ' ' . ($description ?? ''));

        // Global exclusions — clothing/non-sneaker items
        foreach ([
            'toddler', 'newborn', 'shirt', 'tee', 'onesie', 'onsie', 'romper',
            'baby', 'child', 'kids', 'boys', 'girls', 'shorts', 'hoodie', 'sweatshirt',
            'sweatpants', 'sweats', 'sweat', 'long sleeve', 'leggings', 'pants',
            'joggers', 'backpack', 'back pack', 'hat',
        ] as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        // Jordan-specific exclusions
        if (self::deriveCategory($searchTerm) === 'All Jordans') {
            foreach (['card', 'autograph', 'jersey'] as $word) {
                if (str_contains($text, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function normalizePrice(string|float|int|null $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $str     = (string) $raw;
        $cleaned = preg_replace('/[^\d.,]/', '', $str);
        if (!$cleaned) {
            return null;
        }
        // European format detection: 1.234,56
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $cleaned)) {
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } else {
            $cleaned = str_replace(',', '', $cleaned);
        }
        $price = (float) $cleaned;
        return ($price > 0) ? round($price, 2) : null;
    }

    public static function normalizeSize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        return trim($raw);
    }
}
