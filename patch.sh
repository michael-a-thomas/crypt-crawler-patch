#!/usr/bin/env bash
# Crypt Crawler — complete file patch
# Run from inside your crypt-crawler folder:
#   cd ~/Crypt-Crawler/crypt-crawler
#   bash patch.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== Patching Crypt Crawler files ==="
echo ""

echo 'Writing src/Crawler.php...'
mkdir -p "src"
cat > 'src/Crawler.php' << 'CRYPT_PATCH_EOF_'
<?php
declare(strict_types=1);

class Crawler
{
    private static array $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    private static function dataDir(): string
    {
        return PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data';
    }

    private static function cookieFile(): string
    {
        return self::dataDir() . DIRECTORY_SEPARATOR . 'vinted_cookies.txt';
    }

    public static function stopFile(): string
    {
        return self::dataDir() . DIRECTORY_SEPARATOR . 'crawl.stop';
    }

    public static function lockFile(): string
    {
        return self::dataDir() . DIRECTORY_SEPARATOR . 'crawl.running';
    }

    public static function startCrawl(): array
    {
        $status = Database::getCrawlStatus();
        if ($status['status'] === 'running') {
            return ['success' => false, 'error' => 'A crawl is already running.'];
        }

        $dataDir = self::dataDir();
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        if (file_exists(self::stopFile())) {
            @unlink(self::stopFile());
        }

        $jobId = uniqid('job_', true);
        file_put_contents(self::lockFile(), $jobId);

        Database::updateCrawlStatus([
            'job_id'             => $jobId,
            'status'             => 'running',
            'accepted'           => 0,
            'target'             => 0,
            'duplicates_skipped' => 0,
            'rejected_count'     => 0,
            'source'             => null,
            'search_term'        => null,
            'method'             => null,
            'message'            => 'Starting worker…',
        ]);

        $phpBin     = PHP_BINARY;
        $workerPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'crawl_worker.php';

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf(
                'cmd /C start "" /B %s %s %s',
                escapeshellarg($phpBin),
                escapeshellarg($workerPath),
                escapeshellarg($jobId)
            );
            pclose(popen($cmd, 'r'));
        } else {
            $logFile    = self::dataDir() . '/worker.log';
            $parentPath = getenv('PATH') ?: '';
            $envPath    = '/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin'
                        . ($parentPath !== '' ? ':' . $parentPath : '');
            $cmd = sprintf(
                'PATH=%s %s %s %s >> %s 2>&1 &',
                escapeshellarg($envPath),
                escapeshellarg($phpBin),
                escapeshellarg($workerPath),
                escapeshellarg($jobId),
                escapeshellarg($logFile)
            );
            exec($cmd);
        }

        return ['success' => true, 'job_id' => $jobId, 'message' => 'Crawl started.'];
    }

    public static function stopCrawl(): array
    {
        $dataDir = self::dataDir();
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        file_put_contents(self::stopFile(), (string) time());

        if (file_exists(self::lockFile())) {
            @unlink(self::lockFile());
        }

        Database::updateCrawlStatus([
            'status'  => 'stopping',
            'message' => 'Stop requested…',
        ]);

        return ['success' => true, 'message' => 'Stop signal sent.'];
    }

    public static function shouldStop(string $jobId): bool
    {
        if (file_exists(self::stopFile())) {
            return true;
        }
        $lockPath = self::lockFile();
        if (!file_exists($lockPath)) {
            return true;
        }
        return trim(file_get_contents($lockPath)) !== $jobId;
    }

    public static function initVintedSession(): void
    {
        $cookieFile = self::cookieFile();

        $ch = curl_init('https://www.vinted.com/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => self::$userAgents[0],
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        unset($ch);
        sleep(2);
    }

    public static function crawlVintedPage(string $searchTerm, int $page, int $perPage = 96): array
    {
        $cookieFile = self::cookieFile();
        $ua         = self::$userAgents[array_rand(self::$userAgents)];

        $apiUrl = 'https://www.vinted.com/api/v2/catalog/items?' . http_build_query([
            'search_text' => $searchTerm,
            'page'        => $page,
            'per_page'    => $perPage,
            'order'       => 'newest_first',
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Referer: https://www.vinted.com/catalog',
                'X-Requested-With: XMLHttpRequest',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
            ],
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        unset($ch);

        if ($error) {
            error_log("Vinted cURL error: $error");
            return [];
        }
        if ($httpCode !== 200) {
            error_log("Vinted API HTTP $httpCode for page $page of '$searchTerm'");
            if ($httpCode === 401 || $httpCode === 403) {
                sleep(3);
                self::initVintedSession();
            }
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['items'])) {
            error_log("Vinted unexpected response body for '$searchTerm' page $page");
            return [];
        }

        $totalPages = (int) ($data['pagination']['total_pages'] ?? 1);
        $hasMore    = $page < $totalPages;

        return [
            'listings' => $data['items'],
            'has_more' => $hasMore,
        ];
    }
}
CRYPT_PATCH_EOF_

echo 'Writing src/BrowserHarvester.php...'
mkdir -p "src"
cat > 'src/BrowserHarvester.php' << 'CRYPT_PATCH_EOF_'
<?php
declare(strict_types=1);

class BrowserHarvester
{
    public static function launch(
        string $jobId,
        string $source,
        string $term,
        string $category,
        int    $limit
    ): void {
        $jsScript = __DIR__ . DIRECTORY_SEPARATOR . 'BrowserHarvester.js';

        if (!file_exists($jsScript)) {
            error_log("BrowserHarvester: JS script not found at $jsScript");
            Database::updateCrawlStatus([
                'message' => "BrowserHarvester.js not found — skipping $source/$term",
            ]);
            return;
        }

        $node = 'node';
        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node', '/usr/bin/node'] as $p) {
            if (file_exists($p)) { $node = $p; break; }
        }

        $cmd = [
            $node,
            $jsScript,
            '--source',   $source,
            '--term',     $term,
            '--limit',    (string) $limit,
            '--category', $category,
            '--db',       DB_PATH,
            '--job-id',   $jobId,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            error_log("BrowserHarvester: proc_open failed for $source / $term");
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            $line = fgets($pipes[1]);
            if ($line !== false && $line !== '') {
                error_log("[Harvester/$source] " . rtrim($line));
            }

            if (Crawler::shouldStop($jobId)) {
                proc_terminate($process);
                break;
            }

            usleep(200_000);
        }

        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);
        if ($remainingOut) {
            error_log("[Harvester/$source stdout] " . rtrim($remainingOut));
        }
        if ($remainingErr) {
            error_log("[Harvester/$source stderr] " . rtrim($remainingErr));
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    public static function nodeAvailable(): bool
    {
        foreach (['/usr/local/bin/node', '/opt/homebrew/bin/node', '/usr/bin/node', 'node'] as $bin) {
            $output = [];
            exec(escapeshellarg($bin) . ' --version 2>&1', $output, $code);
            if ($code === 0 && !empty($output)) return true;
        }
        return false;
    }
}
CRYPT_PATCH_EOF_

echo 'Writing src/crawl_worker.php...'
mkdir -p "src"
cat > 'src/crawl_worker.php' << 'CRYPT_PATCH_EOF_'
<?php
declare(strict_types=1);

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

$configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sources.json';
if (!file_exists($configPath)) {
    fwrite(STDERR, "crawl_worker: config/sources.json not found\n");
    Database::updateCrawlStatus(['status' => 'stopped', 'message' => 'sources.json missing']);
    exit(1);
}

$config      = json_decode(file_get_contents($configPath), true);
$searchTerms = $config['search_terms'] ?? [];
$sources     = $config['sources']      ?? [];

$vintedEnabled = ($sources['vinted']['enabled'] ?? false) && ($sources['vinted']['method'] ?? '') === 'php_curl';
if ($vintedEnabled) {
    echo "Initializing Vinted session…\n";
    Crawler::initVintedSession();
}

$nodeOk = BrowserHarvester::nodeAvailable();
if (!$nodeOk) {
    fwrite(STDERR, "crawl_worker: node not found — browser sources will be skipped.\n");
}

foreach ($sources as $sourceName => $sourceConfig) {
    if (!($sourceConfig['enabled'] ?? false)) {
        continue;
    }

    $method = $sourceConfig['method'] ?? 'php_curl';

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

        echo "Crawling $sourceName / \"$term\" (limit $limit)…\n";

        Database::updateCrawlStatus([
            'source'             => $sourceName,
            'search_term'        => $term,
            'method'             => $methodLabel,
            'accepted'           => 0,
            'target'             => $limit,
            'duplicates_skipped' => 0,
            'rejected_count'     => 0,
            'message'            => "Crawling $sourceName for \"$term\"…",
        ]);

        if ($method === 'php_curl') {
            crawlVintedTerm($jobId, $sourceName, $term, $category, $limit);
        } else {
            BrowserHarvester::launch($jobId, $sourceName, $term, $category, $limit);
        }

        if (Crawler::shouldStop($jobId)) {
            break 2;
        }

        sleep(2);
    }
}

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

$lockFile = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'crawl.running';
if (file_exists($lockFile) && trim(file_get_contents($lockFile)) === $jobId) {
    @unlink($lockFile);
}

exit(0);

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
            echo "  Vinted: no listings on page $page — stopping.\n";
            break;
        }

        foreach ($result['listings'] as $raw) {
            if (Crawler::shouldStop($jobId) || $accepted >= $limit) {
                break 2;
            }

            $title      = trim((string) ($raw['title'] ?? ''));
            $desc       = trim((string) ($raw['description'] ?? ''));
            $priceField = $raw['price'] ?? null;
            $priceRaw   = is_array($priceField) ? ($priceField['amount'] ?? null) : $priceField;
            $sizeRaw    = $raw['size_title'] ?? ($raw['size']['title'] ?? null);
            $imageUrl   = $raw['photo']['url']
                       ?? $raw['photo']['full_size_url']
                       ?? $raw['photo']['thumbnails'][0]['url']
                       ?? null;
            $rawUrl     = $raw['url'] ?? ('https://www.vinted.com/items/' . ($raw['id'] ?? ''));

            if (!$title || !$rawUrl) {
                continue;
            }

            if (ListingNormalizer::shouldExclude($title, $desc ?: null, $term)) {
                $rejected++;
                Database::updateCrawlStatus(['rejected_count' => $rejected]);
                continue;
            }

            $canonicalUrl = ListingNormalizer::makeCanonicalUrl($rawUrl, $source);

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
                'message'            => "vinted: \"$term\" — $accepted/$limit accepted",
            ]);
        }

        if (!($result['has_more'] ?? false)) {
            break;
        }

        $page++;
        sleep(random_int(2, 4));
    }

    echo "  Done vinted/\"$term\": accepted=$accepted, dupes=$dupes, rejected=$rejected\n";
}
CRYPT_PATCH_EOF_

echo 'Writing src/BrowserHarvester.js...'
mkdir -p "src"
cat > 'src/BrowserHarvester.js' << 'CRYPT_PATCH_EOF_'
'use strict';

const puppeteer    = require('puppeteer');
const BetterSqlite = require('better-sqlite3');
const path         = require('path');
const fs           = require('fs');
const crypto       = require('crypto');

function parseArgs(argv) {
    const result = {};
    for (let i = 0; i < argv.length - 1; i++) {
        if (argv[i].startsWith('--')) {
            result[argv[i].slice(2)] = argv[i + 1];
            i++;
        }
    }
    return result;
}

const args   = parseArgs(process.argv.slice(2));
const SOURCE = args['source'];
const TERM   = args['term'];
const LIMIT  = parseInt(args['limit'] || '100', 10);
const CAT    = args['category'] || '';
const DB_PATH= args['db'];
const JOB_ID = args['job-id'];

if (!SOURCE || !TERM || !DB_PATH || !JOB_ID) {
    console.error('Usage: node BrowserHarvester.js --source <s> --term <t> --limit <n> --category <c> --db <path> --job-id <id>');
    process.exit(1);
}

const SOURCES = {
    depop: {
        buildUrl:     (q) => `https://www.depop.com/search/?q=${encodeURIComponent(q)}&sort=newlyListed`,
        baseUrl:      'https://www.depop.com',
        linkSelector: 'a[href*="/products/"]',
        normalizeHref:(h) => h.replace(/^(\/products\/[^/?#]+).*$/, '$1'),
    },
    poshmark: {
        buildUrl:     (q) => `https://poshmark.com/search?query=${encodeURIComponent(q)}&sort_by=added_desc&type=listings`,
        baseUrl:      'https://poshmark.com',
        linkSelector: 'a[href*="/listing/"]',
        normalizeHref:(h) => h.replace(/^(\/listing\/[^/?#]+).*$/, '$1'),
    },
    mercari: {
        buildUrl:     (q) => `https://www.mercari.com/search/?keyword=${encodeURIComponent(q)}&sort=created_time&order=desc`,
        baseUrl:      'https://www.mercari.com',
        linkSelector: 'a[href*="/item/m"]',
        normalizeHref:(h) => h.replace(/^(\/item\/m\d+).*$/, '$1'),
    },
};

const srcCfg = SOURCES[SOURCE];
if (!srcCfg) {
    console.error(`Unknown source: ${SOURCE}`);
    process.exit(1);
}

let db;
try {
    db = new BetterSqlite(DB_PATH);
} catch (e) {
    console.error('Failed to open DB:', e.message);
    process.exit(1);
}
db.pragma('journal_mode = WAL');
db.pragma('synchronous = NORMAL');

const stmtInsert = db.prepare(`
    INSERT INTO listings
        (source, canonical_url, url, title, description, price, size, image_url, category, search_term)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
`);

const stmtExists = db.prepare('SELECT 1 FROM listings WHERE canonical_url = ? LIMIT 1');

const stmtStatus = db.prepare(`
    UPDATE crawl_status
    SET source=?, search_term=?, method=?, accepted=?, target=?,
        duplicates_skipped=?, rejected_count=?, status=?, message=?,
        updated_at=CURRENT_TIMESTAMP
    WHERE id=1
`);

function canonicalExists(canonUrl) {
    return !!stmtExists.get(canonUrl);
}

function insertListing(row) {
    try {
        stmtInsert.run(
            row.source, row.canonicalUrl, row.url, row.title,
            row.description || null, row.price || null, row.size || null,
            row.imageUrl || null, row.category || null, row.searchTerm || null
        );
        return 'inserted';
    } catch (e) {
        if (e.message.includes('UNIQUE constraint failed')) return 'duplicate';
        console.error('Insert error:', e.message);
        return 'error';
    }
}

function updateStatus(data) {
    try {
        stmtStatus.run(
            data.source, data.searchTerm, data.method,
            data.accepted, data.target, data.dupesSkipped,
            data.rejectedCount, data.status, data.message
        );
    } catch (e) {
        console.error('Status update error:', e.message);
    }
}

function shouldStop() {
    const stopFile = path.join(path.dirname(DB_PATH), 'crawl.stop');
    const lockFile = path.join(path.dirname(DB_PATH), 'crawl.running');
    if (fs.existsSync(stopFile))  return true;
    if (!fs.existsSync(lockFile)) return true;
    try {
        return fs.readFileSync(lockFile, 'utf8').trim() !== JOB_ID;
    } catch (_) { return true; }
}

function makeCanonicalUrl(fullUrl) {
    try {
        const u    = new URL(fullUrl);
        const norm = srcCfg.normalizeHref(u.pathname);
        let itemId = null;
        if (SOURCE === 'depop')    { const m = norm.match(/\/products\/([a-z0-9_-]+)/i); if (m) itemId = m[1]; }
        if (SOURCE === 'poshmark') { const m = norm.match(/--([\w]{24})(?:[/?]|$)/);      if (m) itemId = m[1]; }
        if (SOURCE === 'mercari')  { const m = norm.match(/\/item\/(m\d+)/);              if (m) itemId = m[1]; }
        if (itemId) return `${SOURCE}:${itemId}`;
        return `${u.protocol}//${u.hostname}${norm}`;
    } catch (_) {
        return `${SOURCE}:${crypto.createHash('md5').update(fullUrl).digest('hex')}`;
    }
}

function normalizeUrl(fullUrl) {
    try {
        const u    = new URL(fullUrl);
        const norm = srcCfg.normalizeHref(u.pathname);
        return `${u.protocol}//${u.hostname}${norm}`;
    } catch (_) { return fullUrl; }
}

function normalizePrice(str) {
    if (!str) return null;
    const cleaned = str.replace(/[^\d.,]/g, '');
    if (!cleaned) return null;
    const price = parseFloat(cleaned.replace(/,/g, ''));
    return (!isNaN(price) && price > 0) ? Math.round(price * 100) / 100 : null;
}

function shouldExclude(title, description) {
    const text = ((title || '') + ' ' + (description || '')).toLowerCase();
    if (text.includes('toddler')) return true;
    if (CAT === 'All Jordans') {
        for (const w of ['shorts', 'card', 'autograph', 'jersey']) {
            if (text.includes(w)) return true;
        }
    }
    return false;
}

async function extractAllCards(page) {
    return await page.evaluate((selector, base) => {
        const links = Array.from(document.querySelectorAll(selector));
        return links.map(link => {
            const href = link.getAttribute('href');
            if (!href) return null;
            const fullUrl = href.startsWith('http') ? href : base + href;
            let container = link;
            for (let i = 0; i < 6; i++) {
                if (container.parentElement) container = container.parentElement;
                else break;
            }
            let title = '';
            const titleSels = ['h3','h2','[class*="title" i]','[class*="name" i]','[data-testid*="title" i]','[data-testid*="name" i]'];
            for (const s of titleSels) {
                const el = container.querySelector(s);
                if (el && el.textContent.trim()) { title = el.textContent.trim(); break; }
            }
            if (!title) title = (link.textContent || '').trim().split('\n')[0].trim();
            if (!title) return null;
            let priceStr = '';
            const priceSels = ['[class*="price" i]','[data-testid*="price" i]','[class*="amount" i]'];
            for (const s of priceSels) {
                const el = container.querySelector(s);
                if (el && /\d/.test(el.textContent)) { priceStr = el.textContent.trim(); break; }
            }
            let imageUrl = '';
            const img = container.querySelector('img');
            if (img) {
                imageUrl = img.getAttribute('src') || img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
                if (imageUrl.startsWith('data:') || imageUrl.length < 10) imageUrl = '';
            }
            let size = '';
            const sizeSels = ['[class*="size" i]','[data-testid*="size" i]'];
            for (const s of sizeSels) {
                const el = container.querySelector(s);
                if (el && el.textContent.trim().length < 25) { size = el.textContent.trim(); break; }
            }
            return { href, fullUrl, title, priceStr, imageUrl, size };
        }).filter(Boolean);
    }, srcCfg.linkSelector, srcCfg.baseUrl);
}

async function main() {
    const browser = await puppeteer.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled',
        ],
    });

    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.setViewport({ width: 1280, height: 900 });
    await page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });

    let accepted     = 0;
    let dupesSkipped = 0;
    let rejected     = 0;
    const seenHrefs  = new Set();

    updateStatus({
        source: SOURCE, searchTerm: TERM, method: 'Browser scroll',
        accepted, target: LIMIT, dupesSkipped, rejectedCount: rejected,
        status: 'running', message: `Opening ${SOURCE} for "${TERM}"...`,
    });

    const searchUrl = srcCfg.buildUrl(TERM);
    console.log(`Navigating to: ${searchUrl}`);

    try {
        await page.goto(searchUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await new Promise(r => setTimeout(r, 4000));
    } catch (e) {
        console.error(`Navigation failed: ${e.message}`);
        await browser.close();
        return;
    }

    let noNewRounds = 0;
    const MAX_EMPTY = 6;

    while (accepted < LIMIT && !shouldStop()) {
        const cards = await extractAllCards(page).catch(() => []);
        let newThisRound = 0;

        for (const card of cards) {
            if (accepted >= LIMIT || shouldStop()) break;
            if (!card.href || seenHrefs.has(card.href)) continue;
            seenHrefs.add(card.href);
            const canonUrl = makeCanonicalUrl(card.fullUrl);
            if (canonicalExists(canonUrl)) { dupesSkipped++; continue; }
            if (shouldExclude(card.title, null)) { rejected++; continue; }
            const price  = normalizePrice(card.priceStr);
            const result = insertListing({
                source:       SOURCE,
                canonicalUrl: canonUrl,
                url:          normalizeUrl(card.fullUrl),
                title:        card.title,
                description:  null,
                price,
                size:         card.size || null,
                imageUrl:     card.imageUrl || null,
                category:     CAT,
                searchTerm:   TERM,
            });
            if (result === 'inserted')  { accepted++; newThisRound++; }
            else if (result === 'duplicate') { dupesSkipped++; }
            updateStatus({
                source: SOURCE, searchTerm: TERM, method: 'Browser scroll',
                accepted, target: LIMIT, dupesSkipped, rejectedCount: rejected,
                status: 'running',
                message: `${SOURCE}: "${TERM}" - ${accepted}/${LIMIT} accepted`,
            });
        }

        if (newThisRound === 0) {
            noNewRounds++;
            if (noNewRounds >= MAX_EMPTY) { console.log('No new items after multiple scrolls - stopping.'); break; }
        } else {
            noNewRounds = 0;
        }

        if (accepted >= LIMIT) break;
        await page.evaluate(() => window.scrollBy(0, window.innerHeight * 2));
        await new Promise(r => setTimeout(r, 2500));
        const atBottom = await page.evaluate(
            () => window.scrollY + window.innerHeight >= document.body.scrollHeight - 200
        ).catch(() => false);
        if (atBottom && newThisRound === 0) { console.log('Reached bottom of page.'); break; }
    }

    await browser.close();
    console.log(`Done ${SOURCE}/"${TERM}": accepted=${accepted}, dupes=${dupesSkipped}, rejected=${rejected}`);
}

main().catch(err => {
    console.error('Fatal harvester error:', err.message);
    process.exit(1);
});
CRYPT_PATCH_EOF_

echo ""
echo "=== All files patched successfully! ==="
echo ""
echo "Now start the server with:"
echo "  php -S 127.0.0.1:8787 -t public public/router.php"
echo ""
echo "Then open http://127.0.0.1:8787 and click Start Crawl."
