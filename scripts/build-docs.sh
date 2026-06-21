#!/bin/bash
# Build bilingual docs site: auto-translate → English (site/en/) + Chinese (site/zh/)
#
# Prerequisites:
#   pip install deep-translator
#
# Usage:
#   bash scripts/build-docs.sh

set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== 1. Translating docs/ → docs-zh/ ==="
python scripts/translate-docs.py

# Copy research docs (bilingual already) and openapi spec verbatim
echo "     Copying research/, ai/, openapi/ → docs-zh/"
rm -rf docs-zh/research docs-zh/openapi docs-zh/ai
cp -r docs/research docs-zh/research 2>/dev/null || true
cp -r docs/openapi docs-zh/openapi 2>/dev/null || true
cp -r docs/ai docs-zh/ai 2>/dev/null || true

echo ""
echo "=== 2. Building English site (site/en/) ==="
mkdocs build --clean

echo ""
echo "=== 3. Building Chinese site (site/zh/) ==="
mkdocs build -f mkdocs-zh.yml --clean

echo ""
echo "=== Done ==="
echo "  English: site/en/index.html"
echo "  Chinese: site/zh/index.html"
