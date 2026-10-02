#!/usr/bin/env bash
# Builds an installable plugin ZIP (rss-media-hub/ at the archive root) without tests or dev files.
# Usage: bin/build-zip.sh [output-dir]
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$SRC/../dist}"
VERSION="$(sed -n "s/^ \* Version: *//p" "$SRC/rss-media-hub.php" | tr -d '[:space:]')"
STAGE="$(mktemp -d)"
mkdir -p "$STAGE/rss-media-hub" "$OUT"
( cd "$SRC" && tar cf - \
	--exclude=./tests --exclude=./bin --exclude=./vendor --exclude=./node_modules \
	--exclude=./.phpunit.cache --exclude=./phpunit.xml.dist --exclude=./phpcs.xml.dist \
	--exclude='*.zip' --exclude=.DS_Store --exclude=./.gitignore . ) | ( cd "$STAGE/rss-media-hub" && tar xf - )
ZIP="$(cd "$OUT" && pwd)/rss-media-hub-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr -X "$ZIP" rss-media-hub )
rm -rf "$STAGE"
( cd "$(dirname "$ZIP")" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )
echo "$ZIP"
