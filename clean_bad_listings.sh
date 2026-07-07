#!/usr/bin/env bash
# Updates ListingNormalizer.php with expanded filter list
# AND deletes already-saved listings that match the bad words
set -e
cd /Users/mthomas/Crypt-Crawler/crypt-crawler

echo '=== Updating ListingNormalizer.php ==='
curl -sL https://raw.githubusercontent.com/michael-a-thomas/crypt-crawler-patch/main/ListingNormalizer.php \
  -o src/ListingNormalizer.php
echo 'Done.'

echo ''
echo '=== Removing bad listings from database ==='
DB=data/crypt_crawler.sqlite
WORDS="toddler shirt tee onesie onsie romper baby child kids hoodie sweatshirt sweatpants sweats sweat joggers backpack pants long sleeve back pack"

for word in $WORDS; do
  COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM listings WHERE LOWER(title) LIKE '%${word}%' OR LOWER(description) LIKE '%${word}%';")
  if [ "$COUNT" -gt 0 ]; then
    echo "  Deleting $COUNT listings matching: $word"
    sqlite3 "$DB" "DELETE FROM listings WHERE LOWER(title) LIKE '%${word}%' OR LOWER(description) LIKE '%${word}%';"
  fi
done

# Handle multi-word phrases separately
for phrase in "long sleeve" "back pack"; do
  COUNT=$(sqlite3 "$DB" "SELECT COUNT(*) FROM listings WHERE LOWER(title) LIKE '%${phrase}%' OR LOWER(description) LIKE '%${phrase}%';")
  if [ "$COUNT" -gt 0 ]; then
    echo "  Deleting $COUNT listings matching: $phrase"
    sqlite3 "$DB" "DELETE FROM listings WHERE LOWER(title) LIKE '%${phrase}%' OR LOWER(description) LIKE '%${phrase}%';"
  fi
done

TOTAL=$(sqlite3 "$DB" "SELECT COUNT(*) FROM listings;")
echo ''
echo "Done. $TOTAL listings remain in database."
