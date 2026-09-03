#!/usr/bin/env bash
#
# proef.sh - Laat een mens dit package proefdraaien.
#
# Dit is GEEN Laravel-applicatie maar een Composer/Laravel-package
# (ginkelsoft/laravel-encrypted-search-index) zonder eigen "artisan"
# of "public/index.php". Het package wordt in het echt getest via
# Orchestra Testbench + PHPUnit (SQLite in-memory), dus dat is hier
# de manier van "proefdraaien":
#
#   1. composer install
#   2. .env aanmaken uit .env.example (voor de volledigheid; de
#      testsuite gebruikt zijn eigen env-waarden uit phpunit.xml.dist)
#   3. de testsuite draaien (dit is het equivalent van "migrate --seed"
#      voor een package: het bouwt een in-memory SQLite database op en
#      vult die met testdata)
#   4. een minimale PHP-server op 0.0.0.0:$PORT starten die de
#      testresultaten en package-info toont, zodat er iets te
#      bekijken valt op de opgegeven poort.
#
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${PORT:-8000}"

echo "==> composer install"
composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
  echo "==> .env aanmaken uit .env.example"
  cp .env.example .env
fi

echo "==> testsuite draaien (PHPUnit + Orchestra Testbench, SQLite in-memory)"
OUTPUT_FILE="$(mktemp)"
if vendor/bin/phpunit --testdox --colors=never 2>&1 | tee "$OUTPUT_FILE"; then
  STATUS="OK"
else
  STATUS="MISLUKT"
fi

echo "==> testresultaat: $STATUS"

DOCROOT="$(mktemp -d)"
{
  echo "<!doctype html><html><head><meta charset='utf-8'><title>laravel-encrypted-search-index - proefdraai</title></head><body>"
  echo "<h1>ginkelsoft/laravel-encrypted-search-index</h1>"
  echo "<p>Dit is een Composer/Laravel-package zonder eigen webserver. Deze pagina toont de uitkomst van de testsuite als proefdraai.</p>"
  echo "<h2>Testresultaat: $STATUS</h2>"
  echo "<pre>"
  sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' "$OUTPUT_FILE"
  echo "</pre>"
  echo "</body></html>"
} > "$DOCROOT/index.html"

echo "==> server starten op 0.0.0.0:$PORT (testresultaten bekijken in de browser)"
exec php -S "0.0.0.0:${PORT}" -t "$DOCROOT"
