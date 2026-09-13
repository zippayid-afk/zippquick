#!/usr/bin/env bash
#
# Build a FULL SnapBuy package (.zip) to hand to a client for a fresh install —
# the whole project, minus dev-only / machine-specific / sensitive files.
#
# EXCLUDED (per delivery policy):
#   node_modules/            — client runs `npm install` if they build assets
#   .env                     — secrets / machine config (ship .env.example instead)
#   config/firebase.json     — private service-account credentials
#   storage/installed        — install marker (so the client's installer runs)
#   storage/app/public/*     — uploaded media / images (client starts clean)
#   runtime junk             — logs, framework cache/sessions/views, debugbar,
#                              bootstrap/cache, .git, .DS_Store, existing zips
#
# KEPT: vendor/ (so it runs without composer), public/build (built assets),
#       .env.example, everything else.
#
# BEFORE running: rebuild the frontend so public/build is current:  npm run build
#
# Usage:  bash scripts/make-client-package.sh [output.zip]

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="unknown"
if [ -f version.txt ]; then
  VERSION="$(tr -d '[:space:]' < version.txt)"
fi

OUT="${1:-$ROOT/snapbuy-${VERSION}.zip}"

if [ ! -d public/build ]; then
  echo "WARNING: public/build missing — run 'npm run build' first (shipping stale/no assets)." >&2
fi
if [ ! -d vendor ]; then
  echo "WARNING: vendor/ missing — run 'composer install' first (client package won't run out of the box)." >&2
fi

rm -f "$OUT"
echo "Packaging FULL client build ${VERSION} -> ${OUT}"

# Zip the whole project, excluding the delivery-policy paths + runtime junk.
zip -r -q "$OUT" . \
  -x '.git/*' \
  -x '.vscode/*' \
  -x '.cursor/*' \
  -x '.claude/*' \
  -x '.well-known/*' \
  -x 'node_modules/*' \
  -x '.env' \
  -x 'config/firebase.json' \
  -x 'public/storage' \
  -x 'public/storage/*' \
  -x 'storage/installed' \
  -x 'storage/installed/*' \
  -x 'storage/app/public/*' \
  -x 'storage/*.key' \
  -x 'storage/oauth-private.key' \
  -x 'storage/oauth-public.key' \
  -x 'storage/logs/[!.]*' \
  -x 'storage/debugbar/[!.]*' \
  -x 'storage/framework/cache/data/[!.]*' \
  -x 'storage/framework/sessions/[!.]*' \
  -x 'storage/framework/views/[!.]*' \
  -x 'bootstrap/cache/[!.]*' \
  -x 'snapbuy-*.zip' \
  -x "$(basename "$OUT")" \
  -x '**/.DS_Store'

echo "Done: $OUT"
ls -lh "$OUT" | awk '{print $5, $9}'

echo "Sanity — excluded paths must NOT appear:"
for p in '.env' 'config/firebase.json' 'storage/installed/' 'node_modules/'; do
  if unzip -l "$OUT" | grep -qE " $p"; then
    echo "  !! LEAKED: $p is in the zip"
  else
    echo "  ok: $p excluded"
  fi
done
echo "  storage/app/public media files: $(unzip -l "$OUT" | grep -cE ' storage/app/public/.+') (should be 0)"
