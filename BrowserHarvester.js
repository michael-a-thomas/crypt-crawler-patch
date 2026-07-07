'use strict';

/**
 * BrowserHarvester.js - Puppeteer-based scraper for Depop, Poshmark, Mercari
 */

const path         = require('path');
const fs           = require('fs');
const crypto       = require('crypto');
// node_modules lives in the project root (one level up from src/)
const puppeteer    = require(path.join(__dirname, '..', 'node_modules', 'puppeteer'));
const BetterSqlite = require(path.join(__dirname, '..', 'node_modules', 'better-sqlite3'));

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

function canonicalExists(canonUrl) { return !!stmtExists.get(canonUrl); }

function insertListing(row) {
    try {
        stmtInsert.run(row.source, row.canonicalUrl, row.url, row.title,
            row.description || null, row.price || null, row.size || null,
            row.imageUrl || null, row.category || null, row.searchTerm || null);
        return 'inserted';
    } catch (e) {
        if (e.message.includes('UNIQUE constraint failed')) return 'duplicate';
        console.error('Insert error:', e.message);
        return 'error';
    }
}

function updateStatus(data) {
    try {
        stmtStatus.run(data.source, data.searchTerm, data.method,
            data.accepted, data.target, data.dupesSkipped,
            data.rejectedCount, data.status, data.message);
    } catch (e) { console.error('Status update error:', e.message); }
}

function shouldStop() {
    const stopFile = path.join(path.dirname(DB_PATH), 'crawl.stop');
    const lockFile = path.join(path.dirname(DB_PATH), 'crawl.running');
    if (fs.existsSync(stopFile))  return true;
    if (!fs.existsSync(lockFile)) return true;
    try { return fs.readFileSync(lockFile, 'utf8').trim() !== JOB_ID; }
    catch (_) { return true; }
}

function makeCanonicalUrl(fullUrl) {
    try {
        const u    = new URL(fullUrl);
        const norm = srcCfg.normalizeHref(u.pathname);
        let itemId = null;
        if (SOURCE === 'depop')    { const m = norm.match(/\/products\/([a-z0-9_-]+)/i); if (m) itemId = m[1]; }
        if (SOURCE === 'poshmark') { const m = norm.match(/--(\w{24})(?:[/?]|$)/);       if (m) itemId = m[1]; }
        if (SOURCE === 'mercari')  { const m = norm.match(/\/item\/(m\d+)/);              if (m) itemId = m[1]; }
        if (itemId) return `${SOURCE}:${itemId}`;
        return `${u.protocol}//${u.hostname}${norm}`;
    } catch (_) {
        return `${SOURCE}:${crypto.createHash('md5').update(fullUrl).digest('hex')}`;
    }
}

function normalizeUrl(fullUrl) {
    try {
        const u = new URL(fullUrl);
        return `${u.protocol}//${u.hostname}${srcCfg.normalizeHref(u.pathname)}`;
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
    const globalWords = ['toddler','shirt','tee','onesie','onsie','romper','baby','child','shorts','hoodie','sweatshirt'];
    for (const w of globalWords) { if (text.includes(w)) return true; }
    if (CAT === 'All Jordans') {
        for (const w of ['card','autograph','jersey']) { if (text.includes(w)) return true; }
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
            for (const s of ['h3','h2','[class*="title" i]','[class*="name" i]','[data-testid*="title" i]']) {
                const el = container.querySelector(s);
                if (el && el.textContent.trim()) { title = el.textContent.trim(); break; }
            }
            if (!title) title = (link.textContent || '').trim().split('\n')[0].trim();
            if (!title) return null;
            let priceStr = '';
            for (const s of ['[class*="price" i]','[data-testid*="price" i]','[class*="amount" i]']) {
                const el = container.querySelector(s);
                if (el && /\d/.test(el.textContent)) { priceStr = el.textContent.trim(); break; }
            }
            let imageUrl = '';
            const img = container.querySelector('img');
            if (img) {
                imageUrl = img.getAttribute('src') || img.getAttribute('data-src') || '';
                if (imageUrl.startsWith('data:') || imageUrl.length < 10) imageUrl = '';
            }
            let size = '';
            for (const s of ['[class*="size" i]','[data-testid*="size" i]']) {
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
        args: ['--no-sandbox','--disable-setuid-sandbox','--disable-blink-features=AutomationControlled'],
    });
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.setViewport({ width: 1280, height: 900 });
    await page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });

    let accepted = 0, dupesSkipped = 0, rejected = 0;
    const seenHrefs = new Set();

    updateStatus({ source: SOURCE, searchTerm: TERM, method: 'Browser scroll',
        accepted, target: LIMIT, dupesSkipped, rejectedCount: rejected,
        status: 'running', message: `Opening ${SOURCE} for "${TERM}"...` });

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
            const result = insertListing({
                source: SOURCE, canonicalUrl: canonUrl, url: normalizeUrl(card.fullUrl),
                title: card.title, description: null, price: normalizePrice(card.priceStr),
                size: card.size || null, imageUrl: card.imageUrl || null,
                category: CAT, searchTerm: TERM,
            });
            if (result === 'inserted') { accepted++; newThisRound++; }
            else if (result === 'duplicate') { dupesSkipped++; }
            updateStatus({ source: SOURCE, searchTerm: TERM, method: 'Browser scroll',
                accepted, target: LIMIT, dupesSkipped, rejectedCount: rejected,
                status: 'running', message: `${SOURCE}: "${TERM}" - ${accepted}/${LIMIT} accepted` });
        }

        if (newThisRound === 0) {
            if (++noNewRounds >= 6) { console.log('No new items - stopping.'); break; }
        } else { noNewRounds = 0; }

        if (accepted >= LIMIT) break;
        await page.evaluate(() => window.scrollBy(0, window.innerHeight * 2));
        await new Promise(r => setTimeout(r, 2500));
        const atBottom = await page.evaluate(
            () => window.scrollY + window.innerHeight >= document.body.scrollHeight - 200
        ).catch(() => false);
        if (atBottom && newThisRound === 0) { console.log('Reached bottom.'); break; }
    }

    await browser.close();
    console.log(`Done ${SOURCE}/"${TERM}": accepted=${accepted}, dupes=${dupesSkipped}, rejected=${rejected}`);
}

main().catch(err => { console.error('Fatal error:', err.message); process.exit(1); });
