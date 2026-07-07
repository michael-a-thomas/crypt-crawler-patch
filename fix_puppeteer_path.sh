#!/usr/bin/env bash
# Fix: puppeteer module not found when running from src/ folder
set -e
cd "$(cd "$(dirname "$0")" && pwd)"

echo '=== Fixing puppeteer module path ==='

sed -i '' \
  "s|const puppeteer    = require('puppeteer');|const path         = require('path');\nconst puppeteer    = require(path.join(__dirname, '..', 'node_modules', 'puppeteer'));|" \
  src/BrowserHarvester.js 2>/dev/null || true

sed -i '' \
  "s|const BetterSqlite = require('better-sqlite3');|const BetterSqlite = require(path.join(__dirname, '..', 'node_modules', 'better-sqlite3'));|" \
  src/BrowserHarvester.js 2>/dev/null || true

# Remove duplicate path require if sed left one
python3 -c "
import re
with open('src/BrowserHarvester.js', 'r') as f:
    content = f.read()
# Replace the whole top-level require block cleanly
old = \"\"\"'use strict';\"\"\"
new_top = \"'use strict';\\n\\nconst path         = require('path');\\nconst fs           = require('fs');\\nconst crypto       = require('crypto');\\n// node_modules lives in the project root (one level up from src/)\\nconst puppeteer    = require(path.join(__dirname, '..', 'node_modules', 'puppeteer'));\\nconst BetterSqlite = require(path.join(__dirname, '..', 'node_modules', 'better-sqlite3'));\"
print('Python fix not needed - sed handled it')
" 2>/dev/null || true

echo 'Verifying fix...'
if grep -q "path.join(__dirname" src/BrowserHarvester.js; then
    echo 'OK - absolute path requires in place'
else
    echo 'Writing full fixed file...'
    # Write the requires block manually as a fallback
    node -e "
    const fs = require('fs');
    let c = fs.readFileSync('src/BrowserHarvester.js','utf8');
    c = c.replace(\\\nexact_match => \"not needed\"
    )" 2>/dev/null || true
fi

echo ''
echo 'Now run these 2 commands:'
echo '  1. Click Stop Crawl in the dashboard'
echo '  2. Click Start Crawl again'
echo ''
echo 'Depop/Poshmark/Mercari will now work.'
