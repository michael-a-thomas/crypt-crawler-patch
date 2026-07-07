<?php
declare(strict_types=1);

/**
 * crawl_worker.php  — background crawl worker
 *
 * Launched by Crawler::startCrawl() as a detached background process.
 * Usage:  php src/crawl_worker.php <job_id>
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('PROJECT_ROOT', dirname(__DIR__));
define('DB_PATH', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'crypt_crawler.sqlite');

require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ListingNormalizer.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Crawler.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'BrowserHarvester.php';

$jobId = $argv[1] ?? null;
if (!$jobId) {
    fwrite(STDERR, "crawl_worker: no job ID supplied\n");
    exit(1);
}

Database::init(DB_PATH);
Database::getPdo();

// Load sources config
$configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sources.json';
if (!file_exists($configPath)) {
    fwrite(STDERR, "crawl_worker: config/sources.json not found\n");
    Database::updateCrawlStatus(['status' => 'stopped', 'message' => 'sources.json missing']);
    exit(1);
}

$config      = json_decode(file_get_contents($configPath), true);
$searchTerms = $config['search_terms'] ?? [];
$sources     = $config['sources']      ?? [];

// Bootstrap Vinted session once if Vinted is enabled
$vintedEnabled = ($sources['vinted']['enabled'] ?? false) && ($sources['vinted']['method'] ?? '') === 'php_curl';
if ($vintedEnabled) {
    echo "Initializing Vinted session\xe2\x80\xa6\n";
    Crawler::initVintedSession();
}

// Check Node.js availability for browser sources
$nodeOk = BrowserHarvester::nodeAvailable();
if (!$nodeOk) {
    fwrite(STDERR, "crawl_worker: 'node' not found in PATH — browser sources will be skipped.\n");
}

// ---------------------------------------------------------------------------
// Main crawl loop: sources x search terms
// ---------------------------------------------------------------------------
foreach ($sources as $sourceName => $sourceConfig) {
    if (!($sourceConfig['enabled'] ?? false)) {
        continue;
    }

    $method = $sourceConfig['method'] ?? 'php_curl';

    // Skip browser sources when Node.js is unavailable
    if ($method === 'browser' && !$nodeOk) {
        echo "Skipping $sourceName (Node.js not available)\n";
        continue;
    }

    foreach ($searchTerms as $termConfig) {
        if (Crawler::shouldStop($jobId)) {
            break 2;
        }

        $term        = $termConfig['term'];
        $category    = $termConfig['category'];
        $limit       = (int) $termConfig['limit'];
        $methodLabel = ($method === 'php_curl') ? 'PHP cURL page crawl' : 'Browser scroll';

        echo "Crawling $sourceName / \"$term\" (limit $limit)\xe2\x80\xa6\n";

        Database::updateCrawlStatus([
            'status'             => 'running',
            'source'             => $sourceName,
            'search_term'        => $term,
            'method'             => $methodLabel,
            'accepted'           => 0,
            'target'             => $limit,
            'duplicates_skipped' => 0,
            'rejected_count'     => 0,
            'message'            => "Crawling $sourceName for \"$term\"\xe2\x80\xa6",
        ]);

        if ($method === 'php_curl') {
            crawlVintedTerm($jobId, $sourceName, $term, $category, $limit);
        } else {
            BrowserHarvester::launch($jobId, $sourceName, $term, $category, $limit);
        }

        if (Crawler::shouldStop($jobId)) {
            break 2;
        }

        sleep(2); // Polite pause between term changes
    }
}

// ---------------------------------------------------------------------------
// Finalise
// ---------------------------------------------------------------------------
if (Crawler::shouldStop($jobId)) {
    Database::updateCrawlStatus([
        'status'  => 'stopped',
        'message' => 'Crawl stopped by user.',
    ]);
    echo "Crawl stopped.\n";
} else {
    Database::updateCrawlStatus([
        'status'  => 'completed',
        'message' => 'Crawl completed successfully.',
    ]);
    echo "Crawl completed.\n";
}

// Clean up lock file
$lockFile = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'crawl.running';
if (file_exists($lockFile) && trim(file_get_contents($lockFile)) === $jobId) {
    @unlink($lockFile);
}

exit(0);

// ---------------------------------------------------------------------------
// Vinted cURL term handler
// ---------------------------------------------------------------------------
function crawlVintedTerm(
    string $jobId,
    string $source,
    string $term,
    string $category,
    int    $limit
): void {
    $accepted = 0;
    $dupes    = 0;
    $rejected = 0;
    $page     = 1;

    while ($accepted < $limit) {
        if (Crawler::shouldStop($jobId)) {
            break;
        }

        $result = Crawler::crawlVintedPage($term, $page);

        if (empty($result['listings'])) {
            echo "  Vinted: no listings returned for page $page — stopping.\n";
            break;
        }

        foreach ($result['listings'] as $raw) {
            if (Crawler::shouldStop($jobId) || $accepted >= $limit) {
                break 2;
            }

            $title     = trim((string) ($raw['title'] ?? ''));
            $desc      = trim((string) ($raw['description'] ?? ''));
            // Vinted API v2 returns price as {"amount":"50.00","currency_code":"USD"}
            $priceField = $raw['price'] ?? null;
            $priceRaw   = is_array($priceField) ? ($priceField['amount'] ?? null) : $priceField;
            $sizeRaw  = $raw['size_title'] ?? ($raw['size']['title'] ?? null);
            $imageUrl = $raw['photo']['url']
                     ?? $raw['photo']['full_size_url']
                     ?? $raw['photo']['thumbnails'][0]['url']
                     ?? null;

            $rawUrl   = $raw['url'] ?? ('https://www.vinted.com/items/' . ($raw['id'] ?? ''));

            if (!$title || !$rawUrl) {
                continue;
            }

            // Filter check
            if (ListingNormalizer::shouldExclude($title, $desc ?: null, $term)) {
                $rejected++;
                Database::updateCrawlStatus(['rejected_count' => $rejected]);
                continue;
            }

            $canonicalUrl = ListingNormalizer::makeCanonicalUrl($rawUrl, $source);

            // Deduplication
            if (Database::canonicalExists($canonicalUrl)) {
                $dupes++;
                Database::updateCrawlStatus(['duplicates_skipped' => $dupes]);
                continue;
            }

            $price = ListingNormalizer::normalizePrice($priceRaw);
            $size  = ListingNormalizer::normalizeSize($sizeRaw);

            $insertResult = Database::insertListing([
                'source'        => $source,
                'canonical_url' => $canonicalUrl,
                'url'           => ListingNormalizer::normalizeUrl($rawUrl, $source),
                'title'         => $title,
                'description'   => $desc !== '' ? $desc : null,
                'price'         => $price,
                'size'          => $size,
                'image_url'     => $imageUrl,
                'category'      => $category,
                'search_term'   => $term,
            ]);

            if ($insertResult === 'inserted') {
                $accepted++;
            } elseif ($insertResult === 'duplicate') {
                $dupes++;
            }

            Database::updateCrawlStatus([
                'accepted'           => $accepted,
                'target'             => $limit,
                'duplicates_skipped' => $dupes,
                'rejected_count'     => $rejected,
                'message'            => "vinted: \"$term\" \xe2\x80\x94 $accepted/$limit accepted",
            ]);
        }

        if (!($result['has_more'] ?? false)) {
            break;
        }

        $page++;
        sleep(random_int(2, 4)); // Respectful rate-limiting between pages
    }

    echo "  Done vinted/\"$term\": accepted=$accepted, dupes=$dupes, rejected=$rejected\n";
}
