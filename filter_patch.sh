#!/usr/bin/env bash
# Crypt Crawler - clothing filter patch v2
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== Applying clothing filter patch v2 ==="
echo ""

echo 'Updating src/ListingNormalizer.php...'
cat > 'src/ListingNormalizer.php' << 'CRYPT_EOF'
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
        if (!$url) return '';
        if (!str_starts_with($url, 'http')) {
            $url = 'https:' . ltrim($url, ':');
        }
        $parsed = parse_url($url);
        if (empty($parsed['host'])) return $url;
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = strtolower($parsed['host']);
        $path   = rtrim($parsed['path'] ?? '', '/');
        $query  = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            foreach (self::STRIP_PARAMS as $p) unset($query[$p]);
        }
        $path = match ($source) {
            'vinted'   => (preg_match('/\/items\/(\d+)/', $path, $m) ? '/items/' . $m[1] : $path),
            'depop'    => (preg_match('/\/products\/([^\/\?]+)/', $path, $m) ? '/products/' . $m[1] : $path),
            'poshmark' => (preg_match('/\/listing\/([^\/\?]+)/', $path, $m) ? '/listing/' . $m[1] : $path),
            'mercari'  => (preg_match('/\/item\/(m\d+)/', $path, $m) ? '/item/' . $m[1] : $path),
            default    => $path,
        };
        $normalized = $scheme . '://' . $host . $path;
        if ($query) $normalized .= '?' . http_build_query($query);
        return $normalized;
    }

    public static function extractItemId(string $url, string $source): ?string
    {
        return match ($source) {
            'vinted'   => (preg_match('/\/items\/(\d+)/', $url, $m) ? $m[1] : null),
            'depop'    => (preg_match('/\/products\/([a-zA-Z0-9\-_]+)/', $url, $m) ? $m[1] : null),
            'poshmark' => (preg_match('/--([\w]{24})(?:[\/\?]|$)/', $url, $m) ? $m[1] : null),
            'mercari'  => (preg_match('/\/item\/(m\d+)/', $url, $m) ? $m[1] : null),
            default    => null,
        };
    }

    public static function makeCanonicalUrl(string $url, string $source): string
    {
        $normalized = self::normalizeUrl($url, $source);
        $itemId     = self::extractItemId($normalized, $source);
        if ($itemId) return $source . ':' . $itemId;
        return $normalized ?: ($source . ':' . md5($url));
    }

    public static function deriveCategory(string $searchTerm): string
    {
        $term = strtoupper(trim($searchTerm));
        if (in_array($term, ['SB DUNK', 'NIKE SB DUNK', 'NIKE DUNK'], true)) return 'All Dunks';
        if (preg_match('/^(NIKE\s+)?AIR JORDAN(\s+\d+)?$/', $term)) return 'All Jordans';
        if (in_array($term, ['AIR MAX', 'NIKE AIR MAX'], true)) return 'All Max';
        return 'Other';
    }

    public static function shouldExclude(string $title, ?string $description, string $searchTerm): bool
    {
        $text = strtolower($title . ' ' . ($description ?? ''));

        // Global exclusions - clothing/non-sneaker items
        foreach (['toddler', 'shirt', 'tee', 'onesie', 'onsie', 'romper', 'baby', 'child', 'shorts', 'hoodie', 'sweatshirt'] as $word) {
            if (str_contains($text, $word)) return true;
        }

        // Jordan-specific exclusions
        if (self::deriveCategory($searchTerm) === 'All Jordans') {
            foreach (['card', 'autograph', 'jersey'] as $word) {
                if (str_contains($text, $word)) return true;
            }
        }

        return false;
    }

    public static function normalizePrice(string|float|int|null $raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $str     = (string) $raw;
        $cleaned = preg_replace('/[^\d.,]/', '', $str);
        if (!$cleaned) return null;
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
        if ($raw === null || trim($raw) === '') return null;
        return trim($raw);
    }
}
CRYPT_EOF

echo 'Updating config/sources.json...'
cat > 'config/sources.json' << 'CRYPT_EOF'
{
  "search_terms": [
    { "term": "SB DUNK",         "category": "All Dunks",   "limit": 100 },
    { "term": "NIKE SB DUNK",    "category": "All Dunks",   "limit": 100 },
    { "term": "NIKE DUNK",       "category": "All Dunks",   "limit": 100 },
    { "term": "AIR JORDAN 1",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 2",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 3",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 4",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 5",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 6",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 7",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 8",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 9",    "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 10",   "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 11",   "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 12",   "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN 13",   "category": "All Jordans", "limit": 50  },
    { "term": "AIR JORDAN",      "category": "All Jordans", "limit": 50  },
    { "term": "NIKE AIR JORDAN", "category": "All Jordans", "limit": 50  },
    { "term": "AIR MAX",         "category": "All Max",     "limit": 100 },
    { "term": "NIKE AIR MAX",    "category": "All Max",     "limit": 100 }
  ],
  "sources": {
    "vinted": {
      "method": "php_curl",
      "enabled": true,
      "api_url": "https://www.vinted.com/api/v2/catalog/items",
      "home_url": "https://www.vinted.com/",
      "items_per_page": 96,
      "sort_param": "newest_first"
    },
    "depop": {
      "method": "browser",
      "enabled": true,
      "search_url": "https://www.depop.com/search/",
      "sort_param": "newlyListed",
      "link_pattern": "/products/"
    },
    "poshmark": {
      "method": "browser",
      "enabled": true,
      "search_url": "https://poshmark.com/search",
      "sort_param": "added_desc",
      "link_pattern": "/listing/"
    },
    "mercari": {
      "method": "browser",
      "enabled": true,
      "search_url": "https://www.mercari.com/search/",
      "sort_param": "created_time_desc",
      "link_pattern": "/item/m"
    }
  },
  "filters": {
    "global_exclude":  ["toddler", "shirt", "tee", "onesie", "onsie", "romper", "baby", "child", "shorts", "hoodie", "sweatshirt"],
    "jordan_exclude":  ["card", "autograph", "jersey"],
    "jordan_categories": ["All Jordans"]
  }
}
CRYPT_EOF

echo 'Removing matching listings from database...'
DB="data/crypt_crawler.sqlite"
if [ -f "$DB" ]; then
    sqlite3 "$DB" "DELETE FROM listings WHERE LOWER(title) LIKE '%shirt%' OR LOWER(title) LIKE '%onesie%' OR LOWER(title) LIKE '%onsie%' OR LOWER(title) LIKE '%romper%' OR LOWER(title) LIKE '%baby%' OR LOWER(title) LIKE '%child%' OR LOWER(title) LIKE '%shorts%' OR LOWER(title) LIKE '% tee %' OR LOWER(title) LIKE '% tee' OR LOWER(title) LIKE 'tee %' OR LOWER(title) LIKE '%hoodie%' OR LOWER(title) LIKE '%sweatshirt%' OR LOWER(title) LIKE '%toddler%';"
    echo "Remaining listings: $(sqlite3 \"$DB\" 'SELECT COUNT(*) FROM listings;')"
else
    echo "No database yet - filters apply on next crawl."
fi

echo ""
echo "=== Done! ==="
echo "Click Stop Crawl, then Clear All Listings, then Start Crawl again."
