<?php
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
define('DB_PATH', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'crypt_crawler.sqlite');

require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'ListingNormalizer.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Crawler.php';
require_once PROJECT_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'BrowserHarvester.php';

Database::init(DB_PATH);
Database::getPdo();

$uri    = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ─── API routing ─────────────────────────────────────────────────────────────────────────
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');

    $route = "$method:$uri";
    switch ($route) {

        case 'GET:/api/listings':
            $rawSources = $_GET['source'] ?? [];
            $sources    = is_array($rawSources) ? array_values(array_filter($rawSources)) : ($rawSources !== '' ? [$rawSources] : []);
            $filters    = array_filter([
                'source'   => $sources,
                'category' => $_GET['category'] ?? '',
                'size'     => $_GET['size']     ?? '',
            ]);
            $listings   = Database::getListings($filters, $_GET['sort'] ?? 'price_asc');
            echo json_encode(['success' => true, 'listings' => $listings, 'count' => count($listings)]);
            break;

        case 'GET:/api/filters':
            echo json_encode([
                'success' => true,
                'sources' => Database::getUniqueSources(),
                'sizes'   => Database::getUniqueSizes(),
                'total'   => Database::getTotalListings(),
            ]);
            break;

        case 'POST:/api/crawl/start':
            echo json_encode(Crawler::startCrawl());
            break;

        case 'POST:/api/crawl/stop':
            echo json_encode(Crawler::stopCrawl());
            break;

        case 'GET:/api/crawl/status':
            echo json_encode([
                'success' => true,
                'status'  => Database::getCrawlStatus(),
                'total'   => Database::getTotalListings(),
            ]);
            break;

        case 'POST:/api/listings/clear':
            Database::clearListings();
            Database::resetCrawlStatus();
            echo json_encode(['success' => true, 'message' => 'All listings cleared.']);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Not found.']);
    }
    exit;
}

// ─── Dashboard HTML ──────────────────────────────────────────────────────────────────────
$total = Database::getTotalListings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Crypt Crawler</title>
<style>
/* ── Reset & CSS Variables ────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0f0f0f;
  --surface:   #151515;
  --surface2:  #1a1a1a;
  --card:      #1e1e1e;
  --card-h:    #242424;
  --border:    #2c2c2c;
  --border-h:  #3d3d3d;
  --accent:    #00d4aa;
  --accent-lo: rgba(0,212,170,.08);
  --accent-md: rgba(0,212,170,.18);
  --text:      #e6e8f0;
  --muted:     #7a7f8e;
  --price:     #ffd166;
  --red:       #ff5555;
  --blue:      #4da6ff;
  --amber:     #ffb347;

  /* Marketplace brand colors */
  --c-vinted:   #09b1ba;
  --c-depop:    #ff3c3c;
  --c-poshmark: #cc3d72;
  --c-mercari:  #2c6fef;
}

/* ── Base ───────────────────────────────────────────────────────────────────────────── */
html   { scroll-behavior: smooth; }
body   {
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
  font-size: 14px;
  line-height: 1.5;
  min-height: 100vh;
}
a { color: inherit; text-decoration: none; }

/* ── Sticky header shell ───────────────────────────────────────────────────── */
.sticky-shell {
  position: sticky;
  top: 0;
  z-index: 200;
}

/* ── Header ───────────────────────────────────────────────────────────────────────────── */
.header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  height: 58px;
  display: flex;
  align-items: center;
  gap: 14px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.brand__icon  { font-size: 1.3rem; line-height: 1; }
.brand__name  {
  font-size: 1.05rem;
  font-weight: 900;
  letter-spacing: 3.5px;
  color: var(--accent);
  text-transform: uppercase;
}
.brand__count {
  font-size: .68rem;
  color: var(--muted);
  background: rgba(255,255,255,.04);
  border: 1px solid var(--border);
  padding: 2px 9px;
  border-radius: 999px;
  letter-spacing: .4px;
}

.header__spacer { flex: 1; }

.header__controls {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── Buttons ───────────────────────────────────────────────────────────────────────────── */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 16px;
  border: 1px solid transparent;
  border-radius: 7px;
  font-size: .74rem;
  font-weight: 700;
  letter-spacing: .6px;
  cursor: pointer;
  transition: background .15s, border-color .15s, opacity .15s, transform .1s, box-shadow .15s;
  white-space: nowrap;
  text-transform: uppercase;
}
.btn:active:not(:disabled) { transform: scale(.96); }
.btn:disabled              { opacity: .3; cursor: not-allowed; }

.btn--start {
  background: var(--accent);
  color: #000;
}
.btn--start:hover:not(:disabled) {
  background: #00ffe0;
  box-shadow: 0 0 18px rgba(0,212,170,.28);
}

.btn--stop {
  background: transparent;
  color: var(--red);
  border-color: rgba(255,85,85,.35);
}
.btn--stop:hover:not(:disabled) {
  background: rgba(255,85,85,.1);
  border-color: var(--red);
}

.btn--clear {
  background: transparent;
  color: var(--muted);
  border-color: var(--border);
}
.btn--clear:hover:not(:disabled) {
  color: var(--red);
  border-color: rgba(255,85,85,.35);
}

/* ── Progress panel ─────────────────────────────────────────────────────────────────────── */
.progress {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 7px 24px;
  transition: background .4s, border-bottom-color .4s;
}
.progress.is-running  {
  background: rgba(0,212,170,.035);
  border-bottom-color: rgba(0,212,170,.25);
}
.progress.is-done     {
  background: rgba(77,166,255,.03);
  border-bottom-color: rgba(77,166,255,.2);
}
.progress.is-stopped  {
  background: rgba(255,85,85,.03);
  border-bottom-color: rgba(255,85,85,.2);
}

.prg-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 22px;
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 10px;
  border-radius: 999px;
  font-size: .63rem;
  font-weight: 800;
  letter-spacing: 1.6px;
  text-transform: uppercase;
  flex-shrink: 0;
}
.status-badge::before {
  content: '●';
  font-size: .55rem;
}

.sb--idle      { background: rgba(255,255,255,.04); color: var(--muted); }
.sb--idle::before { opacity: .35; }
.sb--running   {
  background: rgba(0,212,170,.14);
  color: var(--accent);
  animation: statusPulse 2s ease-in-out infinite;
}
.sb--stopping  { background: rgba(255,179,71,.14); color: var(--amber); }
.sb--completed { background: rgba(77,166,255,.14); color: var(--blue); }
.sb--stopped   { background: rgba(255,85,85,.14);  color: var(--red); }

@keyframes statusPulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: .65; }
}

.prg-stat {
  font-size: .73rem;
  color: var(--muted);
}
.prg-stat strong {
  color: var(--text);
  font-weight: 600;
}

.prg-bar-wrap {
  flex: 1;
  min-width: 60px;
  max-width: 150px;
  height: 3px;
  background: var(--border);
  border-radius: 2px;
  overflow: hidden;
}
.prg-bar {
  height: 100%;
  background: linear-gradient(90deg, var(--accent), #00ffe0);
  border-radius: 2px;
  transition: width .5s ease;
  width: 0%;
}

/* ── Filter bar ─────────────────────────────────────────────────────────────────────────── */
.filters {
  background: var(--surface2);
  border-bottom: 1px solid var(--border);
  padding: 10px 24px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 24px;
}

.fg {
  display: flex;
  align-items: center;
  gap: 8px;
}
.fg__label {
  font-size: .62rem;
  font-weight: 700;
  color: var(--muted);
  letter-spacing: 1.3px;
  text-transform: uppercase;
  white-space: nowrap;
}

/* ── Source toggle chips ─────────────────────────────────────────────────────────────────── */
.src-chips {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
}

.src-chip {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  border: 1px solid var(--border);
  border-radius: 5px;
  font-size: .72rem;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  transition: all .15s;
  user-select: none;
}
.src-chip:hover { border-color: var(--border-h); color: var(--text); }

.src-chip--vinted.active   { background: rgba(9,177,186,.15);   border-color: rgba(9,177,186,.55);   color: var(--c-vinted);   }
.src-chip--depop.active    { background: rgba(255,60,60,.15);    border-color: rgba(255,60,60,.55);   color: var(--c-depop);    }
.src-chip--poshmark.active { background: rgba(204,61,114,.15);   border-color: rgba(204,61,114,.55);  color: var(--c-poshmark); }
.src-chip--mercari.active  { background: rgba(44,111,239,.15);   border-color: rgba(44,111,239,.55);  color: var(--c-mercari);  }

/* ── Category pills ────────────────────────────────────────────────────────────────────────── */
.pill-row { display: flex; gap: 5px; flex-wrap: wrap; }

.pill {
  padding: 4px 14px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: transparent;
  color: var(--muted);
  font-size: .72rem;
  font-weight: 600;
  cursor: pointer;
  transition: all .15s;
}
.pill:hover { border-color: var(--border-h); color: var(--text); }
.pill.active {
  background: var(--accent);
  border-color: var(--accent);
  color: #000;
  font-weight: 700;
}

/* ── Size select ──────────────────────────────────────────────────────────────────────────── */
.size-sel {
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 4px 11px;
  border-radius: 6px;
  font-size: .72rem;
  cursor: pointer;
  outline: none;
  transition: border-color .15s;
  min-width: 110px;
}
.size-sel:focus { border-color: var(--accent); }

/* ── Sort buttons ─────────────────────────────────────────────────────────────────────────── */
.sort-group {
  display: flex;
  gap: 5px;
  margin-left: auto;
}

.sort-btn {
  padding: 4px 14px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: transparent;
  color: var(--muted);
  font-size: .72rem;
  font-weight: 700;
  cursor: pointer;
  transition: all .15s;
}
.sort-btn:hover { border-color: var(--border-h); color: var(--text); }
.sort-btn.active {
  background: var(--accent-lo);
  border-color: rgba(0,212,170,.4);
  color: var(--accent);
}

/* ── Main area ──────────────────────────────────────────────────────────────────────────── */
.main { padding: 20px 24px; }

/* ── Listing grid ─────────────────────────────────────────────────────────────────────────── */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(235px, 1fr));
  gap: 16px;
}

/* ── Card ─────────────────────────────────────────────────────────────────────────────────── */
.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.card:hover {
  transform: translateY(-3px);
  border-color: var(--border-h);
  box-shadow: 0 10px 30px rgba(0,0,0,.45);
}

/* ── Card image ──────────────────────────────────────────────────────────────────────────── */
.card__thumb {
  position: relative;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  background: #111;
  flex-shrink: 0;
}
.card__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform .35s ease;
}
.card:hover .card__img { transform: scale(1.05); }

.card__no-img {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--muted);
}
.card__no-img-icon { font-size: 2.2rem; opacity: .25; }
.card__no-img-txt  { font-size: .65rem; letter-spacing: 1px; text-transform: uppercase; opacity: .5; }

/* Source badge overlaid on image — top-left */
.card__src-badge {
  position: absolute;
  top: 10px;
  left: 10px;
  font-size: .6rem;
  font-weight: 800;
  letter-spacing: .8px;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 4px;
  color: #fff;
  z-index: 2;
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}
.card__src-badge--vinted   { background: rgba(9,177,186,.88); }
.card__src-badge--depop    { background: rgba(255,60,60,.88); }
.card__src-badge--poshmark { background: rgba(204,61,114,.88); }
.card__src-badge--mercari  { background: rgba(44,111,239,.88); }
.card__src-badge--default  { background: rgba(40,40,40,.88); color: var(--muted); }

/* Price badge overlaid on image — bottom-right */
.card__price-badge {
  position: absolute;
  bottom: 10px;
  right: 10px;
  font-size: .95rem;
  font-weight: 800;
  color: #fff;
  background: rgba(0,0,0,.72);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  padding: 3px 10px;
  border-radius: 6px;
  z-index: 2;
  letter-spacing: .3px;
}

/* ── Card body ──────────────────────────────────────────────────────────────────────────── */
.card__body {
  padding: 13px 14px 14px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.card__title {
  font-size: .84rem;
  font-weight: 600;
  line-height: 1.38;
  color: var(--text);
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.card__size {
  display: inline-block;
  font-size: .65rem;
  font-weight: 600;
  padding: 2px 8px;
  border: 1px solid var(--border-h);
  border-radius: 4px;
  color: var(--muted);
  width: fit-content;
}

.card__desc {
  font-size: .74rem;
  color: var(--muted);
  line-height: 1.45;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  flex: 1;
}

.card__link {
  display: block;
  text-align: center;
  padding: 8px 12px;
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted);
  border-radius: 7px;
  font-size: .72rem;
  font-weight: 700;
  margin-top: auto;
  transition: all .15s;
  letter-spacing: .4px;
}
.card__link:hover {
  background: var(--accent-lo);
  border-color: rgba(0,212,170,.4);
  color: var(--accent);
}

/* ── Empty state ─────────────────────────────────────────────────────────────────────────── */
.empty {
  grid-column: 1 / -1;
  text-align: center;
  padding: 90px 20px;
  color: var(--muted);
}
.empty__icon  { font-size: 3.2rem; margin-bottom: 14px; }
.empty__title { font-size: 1rem; font-weight: 600; color: var(--text); margin-bottom: 7px; }
.empty__sub   { font-size: .82rem; }

/* ── Loading spinner ────────────────────────────────────────────────────────────────────────── */
.spin-wrap {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 90px 20px;
}
.spinner {
  width: 34px;
  height: 34px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Toast notifications ────────────────────────────────────────────────────────────────── */
.toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  padding: 11px 18px;
  border-radius: 8px;
  font-size: .82rem;
  font-weight: 600;
  z-index: 9999;
  animation: toastIn .25s ease;
  max-width: 300px;
}
.toast--ok  {
  background: rgba(0,212,170,.1);
  border: 1px solid rgba(0,212,170,.4);
  color: var(--accent);
}
.toast--err {
  background: rgba(255,85,85,.1);
  border: 1px solid rgba(255,85,85,.4);
  color: var(--red);
}
@keyframes toastIn {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: none; }
}

/* ── Custom scrollbar ─────────────────────────────────────────────────────────────────────── */
::-webkit-scrollbar       { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border-h); }

/* ── Responsive ──────────────────────────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
}
@media (max-width: 680px) {
  .header   { padding: 0 16px; height: 52px; }
  .progress { padding: 7px 16px; }
  .filters  { padding: 10px 16px; gap: 9px 16px; }
  .main     { padding: 14px 16px; }
  .grid     { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  .sort-group { margin-left: 0; }
  .brand__name { letter-spacing: 2px; font-size: .95rem; }
}
@media (max-width: 420px) {
  .grid { grid-template-columns: 1fr; }
  .btn--clear { display: none; }
}
</style>
</head>
<body>

<!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
<!-- ║  STICKY SHELL  (header + progress + filters all stay at top)        ║ -->
<!-- ╚══════════════════════════════════════════════════════════════════════╝ -->
<div class="sticky-shell">

  <!-- ── HEADER ──────────────────────────────────────────────────────────────────── -->
  <header class="header">
    <div class="brand">
      <span class="brand__icon">🔐</span>
      <span class="brand__name">Crypt Crawler</span>
      <span class="brand__count" id="total-count"><?= $total ?> listings</span>
    </div>
    <span class="header__spacer"></span>
    <div class="header__controls">
      <button class="btn btn--start" id="btn-start">▶ Start Crawl</button>
      <button class="btn btn--stop"  id="btn-stop" disabled>■ Stop</button>
      <button class="btn btn--clear" id="btn-clear">✕ Clear</button>
    </div>
  </header>

  <!-- ── PROGRESS PANEL ───────────────────────────────────────────────────────────── -->
  <div class="progress" id="progress-panel">
    <div class="prg-row">
      <span class="status-badge sb--idle" id="prg-badge">Idle</span>
      <span class="prg-stat" id="prg-source-wrap" style="display:none">Source: <strong id="prg-source">—</strong></span>
      <span class="prg-stat" id="prg-term-wrap"   style="display:none">Term: <strong id="prg-term">—</strong></span>
      <span class="prg-stat" id="prg-method-wrap" style="display:none">Method: <strong id="prg-method">—</strong></span>
      <span class="prg-stat" id="prg-count-wrap"  style="display:none">Accepted: <strong id="prg-accepted">0</strong> / <strong id="prg-target">0</strong></span>
      <span class="prg-stat" id="prg-dupes-wrap"  style="display:none">Dupes: <strong id="prg-dupes">0</strong></span>
      <span class="prg-stat" id="prg-rej-wrap"    style="display:none">Filtered: <strong id="prg-rej">0</strong></span>
      <div class="prg-bar-wrap" id="prg-bar-wrap" style="display:none">
        <div class="prg-bar" id="prg-bar"></div>
      </div>
    </div>
  </div>

  <!-- ── FILTER BAR ─────────────────────────────────────────────────────────────────── -->
  <div class="filters">

    <div class="fg">
      <span class="fg__label">Source</span>
      <div class="src-chips">
        <span class="src-chip src-chip--vinted"   data-src="vinted">Vinted</span>
        <span class="src-chip src-chip--depop"    data-src="depop">Depop</span>
        <span class="src-chip src-chip--poshmark" data-src="poshmark">Poshmark</span>
        <span class="src-chip src-chip--mercari"  data-src="mercari">Mercari</span>
      </div>
    </div>

    <div class="fg">
      <span class="fg__label">Category</span>
      <div class="pill-row">
        <button class="pill active" data-cat="">All</button>
        <button class="pill"        data-cat="All Dunks">Dunks</button>
        <button class="pill"        data-cat="All Jordans">Jordans</button>
        <button class="pill"        data-cat="All Max">Air Max</button>
      </div>
    </div>

    <div class="fg">
      <span class="fg__label">Size</span>
      <select class="size-sel" id="size-select">
        <option value="">All Sizes</option>
      </select>
    </div>

    <div class="sort-group">
      <button class="sort-btn active" id="sort-price">Price ↑</button>
      <button class="sort-btn"        id="sort-abc">A–Z</button>
    </div>

  </div>
</div><!-- /.sticky-shell -->

<!-- ╔══════════════════════════════════════════════════════════════════════╗ -->
<!-- ║  MAIN LISTING GRID                                                   ║ -->
<!-- ╚══════════════════════════════════════════════════════════════════════╝ -->
<main class="main">
  <div class="grid" id="listings-grid">
    <div class="empty">
      <div class="empty__icon">👟</div>
      <p class="empty__title">No listings yet</p>
      <p class="empty__sub">Click <strong>Start Crawl</strong> to begin collecting sneaker listings.</p>
    </div>
  </div>
</main>

<script>
/* ════════════════════════════════════════════════════════════════════════════
   Crypt Crawler — Dashboard JS
   ════════════════════════════════════════════════════════════════════════════ */
const App = (() => {
  'use strict';

  // ── State ─────────────────────────────────────────────────────────────────────────────
  let sortState       = 'price_asc';
  let filters         = { sources: [], category: '', size: '' };
  let pollTimer       = null;
  let loading         = false;
  let lastKnownStatus = null; // BUG 1 FIX: tracks previous poll status

  // ── DOM helpers ───────────────────────────────────────────────────────────────────────
  const $   = (id) => document.getElementById(id);
  const esc = (s)  => {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
  };
  const show = (id, visible) => {
    const el = $(id);
    if (el) el.style.display = visible ? '' : 'none';
  };

  // ── Init ──────────────────────────────────────────────────────────────────────────────
  function init() {
    // Control buttons
    $('btn-start').addEventListener('click', startCrawl);
    $('btn-stop' ).addEventListener('click', stopCrawl);
    $('btn-clear').addEventListener('click', clearAll);

    // Sort toggles
    $('sort-price').addEventListener('click', () => toggleSort('price'));
    $('sort-abc'  ).addEventListener('click', () => toggleSort('abc'));

    // Size filter
    $('size-select').addEventListener('change', () => {
      filters.size = $('size-select').value;
      resetSort();
      loadListings();
    });

    // Category pills
    document.querySelectorAll('[data-cat]').forEach(btn => {
      btn.addEventListener('click', () => {
        filters.category = btn.dataset.cat;
        document.querySelectorAll('[data-cat]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        resetSort();
        loadListings();
      });
    });

    // Source chips (toggle active class + update filter state)
    document.querySelectorAll('.src-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        chip.classList.toggle('active');
        filters.sources = Array.from(document.querySelectorAll('.src-chip.active'))
                               .map(c => c.dataset.src);
        resetSort();
        loadListings();
      });
    });

    // Initial data load
    loadListings();
    loadFilters();

    // Start polling — runs every 2.5s but only reloads listings on transition
    pollStatus();
    pollTimer = setInterval(pollStatus, 2500);
  }

  // ── Sort ─────────────────────────────────────────────────────────────────────────────
  function resetSort() {
    sortState = 'price_asc';
    $('sort-price').classList.add('active');
    $('sort-price').textContent = 'Price ↑';
    $('sort-abc').classList.remove('active');
    $('sort-abc').textContent = 'A–Z';
  }

  function toggleSort(type) {
    if (type === 'price') {
      sortState = (sortState === 'price_asc') ? 'price_desc' : 'price_asc';
      $('sort-price').textContent = sortState === 'price_asc' ? 'Price ↑' : 'Price ↓';
      $('sort-price').classList.add('active');
      $('sort-abc').classList.remove('active');
      $('sort-abc').textContent = 'A–Z';
    } else {
      sortState = (sortState === 'title_asc') ? 'title_desc' : 'title_asc';
      $('sort-abc').textContent = sortState === 'title_asc' ? 'A–Z' : 'Z–A';
      $('sort-abc').classList.add('active');
      $('sort-price').classList.remove('active');
    }
    loadListings();
  }

  // ── Load listings ───────────────────────────────────────────────────────────────────────
  async function loadListings() {
    if (loading) return;
    loading = true;

    const grid = $('listings-grid');
    grid.innerHTML = '<div class="spin-wrap"><div class="spinner"></div></div>';

    const params = new URLSearchParams({ sort: sortState });
    filters.sources.forEach(s => params.append('source[]', s));
    if (filters.category) params.set('category', filters.category);
    if (filters.size)     params.set('size',     filters.size);

    try {
      const res  = await fetch('/api/listings?' + params.toString());
      const data = await res.json();
      renderGrid(data.listings || []);
    } catch (_) {
      grid.innerHTML = `
        <div class="empty">
          <div class="empty__icon">⚠️</div>
          <p class="empty__title">Failed to load listings</p>
          <p class="empty__sub">Check the server is running on port 8787.</p>
        </div>`;
    } finally {
      loading = false;
    }
  }

  function renderGrid(listings) {
    const grid = $('listings-grid');
    if (!listings.length) {
      grid.innerHTML = `
        <div class="empty">
          <div class="empty__icon">👟</div>
          <p class="empty__title">No listings found</p>
          <p class="empty__sub">Try adjusting your filters, or start a new crawl.</p>
        </div>`;
      return;
    }
    grid.innerHTML = listings.map(buildCard).join('');
  }

  function buildCard(l) {
    const srcKey   = (l.source || '').toLowerCase();
    const validSrc = ['vinted', 'depop', 'poshmark', 'mercari'];
    const srcClass = validSrc.includes(srcKey) ? 'card__src-badge--' + srcKey : 'card__src-badge--default';
    const srcLabel = l.source
      ? esc(l.source.charAt(0).toUpperCase() + l.source.slice(1).toLowerCase())
      : '';
    const priceStr = (l.price != null && l.price !== '')
      ? '$' + parseFloat(l.price).toFixed(2)
      : null;
    const descStr  = l.description
      ? esc(l.description.substring(0, 120)) + (l.description.length > 120 ? '…' : '')
      : '';

    const imgHtml = l.image_url
      ? `<img class="card__img" src="${esc(l.image_url)}" alt="${esc(l.title)}" loading="lazy"
             onerror="this.parentNode.innerHTML='<div class=\\'card__no-img\\'><span class=\\'card__no-img-icon\\'>👟</span><span class=\\'card__no-img-txt\\''>No Image</span></div>'">`
      : `<div class="card__no-img">
           <span class="card__no-img-icon">👟</span>
           <span class="card__no-img-txt">No Image</span>
         </div>`;

    return `
    <div class="card">
      <div class="card__thumb">
        ${imgHtml}
        ${srcLabel ? `<span class="card__src-badge ${srcClass}">${srcLabel}</span>` : ''}
        ${priceStr ? `<span class="card__price-badge">${esc(priceStr)}</span>` : ''}
      </div>
      <div class="card__body">
        <h3 class="card__title">${esc(l.title)}</h3>
        ${l.size   ? `<span class="card__size">${esc(l.size)}</span>` : ''}
        ${descStr  ? `<p class="card__desc">${descStr}</p>` : ''}
        <a class="card__link" href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">
          View on ${srcLabel || 'Marketplace'} ↗
        </a>
      </div>
    </div>`;
  }

  // ── Load filters (sizes dropdown + total count) ──────────────────────────────────────
  async function loadFilters() {
    try {
      const res  = await fetch('/api/filters');
      const data = await res.json();
      if (!data.success) return;

      $('total-count').textContent = (data.total || 0) + ' listings';

      const sel  = $('size-select');
      const prev = sel.value;
      sel.innerHTML = '<option value="">All Sizes</option>';
      (data.sizes || []).forEach(s => {
        const o = document.createElement('option');
        o.value = o.textContent = s;
        if (s === prev) o.selected = true;
        sel.appendChild(o);
      });
    } catch (_) {}
  }

  // ── Crawl controls ───────────────────────────────────────────────────────────────────────
  async function startCrawl() {
    $('btn-start').disabled = true;
    try {
      const res  = await fetch('/api/crawl/start', { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        toast('Crawl started!', 'ok');
        lastKnownStatus = 'running';
      } else {
        toast(data.error || 'Could not start crawl', 'err');
        $('btn-start').disabled = false;
      }
    } catch (_) {
      toast('Network error', 'err');
      $('btn-start').disabled = false;
    }
  }

  async function stopCrawl() {
    $('btn-stop').disabled = true;
    try {
      const res  = await fetch('/api/crawl/stop', { method: 'POST' });
      const data = await res.json();
      toast(data.success ? 'Stop signal sent.' : (data.error || 'Failed'), data.success ? 'ok' : 'err');
    } catch (_) {
      toast('Network error', 'err');
    } finally {
      $('btn-stop').disabled = false;
    }
  }

  async function clearAll() {
    if (!confirm('Clear all listings? This cannot be undone.')) return;
    $('btn-clear').disabled = true;
    try {
      const res  = await fetch('/api/listings/clear', { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        toast('All listings cleared.', 'ok');
        // Reset all UI state
        lastKnownStatus = null;
        filters = { sources: [], category: '', size: '' };
        document.querySelectorAll('.src-chip').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('[data-cat]').forEach(b => b.classList.remove('active'));
        document.querySelector('[data-cat=""]').classList.add('active');
        resetSort();
        loadListings();
        loadFilters();
      } else {
        toast('Failed to clear', 'err');
      }
    } catch (_) {
      toast('Network error', 'err');
    } finally {
      $('btn-clear').disabled = false;
    }
  }

  // ── Status polling (BUG 1 FIXED) ─────────────────────────────────────────────────────
  //  loadListings() is called ONLY when crawl transitions from
  //  running/stopping → completed/stopped. Every other tick is a cheap
  //  status check only — no grid re-render, no page flicker.
  async function pollStatus() {
    try {
      const res  = await fetch('/api/crawl/status');
      const data = await res.json();
      if (!data.success) return;

      const s             = data.status || {};
      const currentStatus = s.status   || 'idle';

      renderProgress(s, currentStatus);
      $('total-count').textContent = (data.total || 0) + ' listings';

      const running = currentStatus === 'running' || currentStatus === 'stopping';
      $('btn-start').disabled = running;
      $('btn-stop' ).disabled = !running;

      // Only reload when status *transitions* from running/stopping → terminal
      const wasRunning = lastKnownStatus === 'running' || lastKnownStatus === 'stopping';
      const isTerminal = currentStatus === 'completed' || currentStatus === 'stopped';

      if (wasRunning && isTerminal) {
        loadListings();
        loadFilters();
        toast(currentStatus === 'completed' ? '✓ Crawl complete!' : 'Crawl stopped.', 'ok');
      }

      lastKnownStatus = currentStatus;
    } catch (_) {}
  }

  function renderProgress(s, currentStatus) {
    const labels = {
      idle: 'Idle', running: 'Running',
      stopping: 'Stopping', completed: 'Done', stopped: 'Stopped',
    };

    // Panel tint
    const panel = $('progress-panel');
    panel.className = 'progress';
    if (currentStatus === 'running')   panel.classList.add('is-running');
    if (currentStatus === 'completed') panel.classList.add('is-done');
    if (currentStatus === 'stopped')   panel.classList.add('is-stopped');

    // Status badge
    const badge = $('prg-badge');
    badge.className   = 'status-badge sb--' + (currentStatus || 'idle');
    badge.textContent = labels[currentStatus] || currentStatus || 'Idle';

    // Show/hide detail stats only while active
    const active = currentStatus === 'running' || currentStatus === 'stopping';
    show('prg-source-wrap', active);
    show('prg-term-wrap',   active);
    show('prg-method-wrap', active);
    show('prg-count-wrap',  active);
    show('prg-dupes-wrap',  active);
    show('prg-rej-wrap',    active);
    show('prg-bar-wrap',    active);

    if (active) {
      $('prg-source'  ).textContent = s.source              || '—';
      $('prg-term'    ).textContent = s.search_term         || '—';
      $('prg-method'  ).textContent = s.method              || '—';
      $('prg-accepted').textContent = s.accepted            || 0;
      $('prg-target'  ).textContent = s.target              || 0;
      $('prg-dupes'   ).textContent = s.duplicates_skipped  || 0;
      $('prg-rej'     ).textContent = s.rejected_count      || 0;

      const pct = (s.target > 0)
        ? Math.min(100, Math.round((s.accepted / s.target) * 100))
        : 0;
      $('prg-bar').style.width = pct + '%';
    }
  }

  // ── Toast ──────────────────────────────────────────────────────────────────────────────
  function toast(msg, type) {
    const el = document.createElement('div');
    el.className   = 'toast toast--' + type;
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3200);
  }

  return { init };
})();

document.addEventListener('DOMContentLoaded', App.init);
</script>
</body>
</html>
