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

echo "     Copying assets/, research/, ai/, openapi/ → docs-zh/"
rm -rf docs-zh/assets docs-zh/research docs-zh/openapi docs-zh/ai
cp -r docs/assets docs-zh/assets 2>/dev/null || true
cp -r docs/research docs-zh/research 2>/dev/null || true
cp -r docs/openapi docs-zh/openapi 2>/dev/null || true
cp -r docs/ai docs-zh/ai 2>/dev/null || true

echo ""
echo "=== 2. Generating mkdocs-zh.yml ==="
python3 -c "
import yaml, copy

with open('mkdocs.yml') as f:
    en = yaml.safe_load(f)

zh = copy.deepcopy(en)
zh['docs_dir'] = 'docs-zh'
zh['site_dir'] = 'site/zh'

# Translate nav labels (static mapping + auto-translate fallback)
MAP = {
    'Home': '首页', 'Design Contracts': '设计契约',
    'System Architecture': '系统架构', 'API Design': 'API 设计',
    'Data Model': '数据模型', 'Module Design': '模块设计',
    'Controller Design': '控制器设计', 'API Documentation': 'API 文档',
    'System Contracts': '系统契约',
    'Bundles': '模块设计', 'Core Framework': 'Core 框架',
    'Common (CMS)': 'Common (CMS)', 'Trade (E-Commerce)': 'Trade (电商)',
    'Payment': 'Payment (支付)', 'Wallet': 'Wallet (钱包)',
    'Identity (Auth)': 'Identity (鉴权)',
    'Research': '调研', 'EasyWeChat 6.x Usage': 'EasyWeChat 6.x 用法',
    'Huifu Payment API': '汇付支付 API', 'AI Context': 'AI 快照',
    'API Reference': 'API 参考',
}

def _zh_label(s):
    if s in MAP:
        return MAP[s]
    if len(s) < 60 and ' ' in s:
        try:
            from deep_translator import GoogleTranslator
            t = GoogleTranslator(source='en', target='zh-CN')
            result = t.translate(s)
            MAP[s] = result
            return result
        except Exception:
            pass
    return s

def translate_nav(items):
    for item in items:
        if isinstance(item, dict):
            for k in list(item.keys()):
                new_k = _zh_label(k)
                if new_k != k:
                    item[new_k] = item.pop(k)
                val = item[new_k]
                if isinstance(val, list):
                    translate_nav(val)
translate_nav(zh.get('nav', []))

with open('mkdocs-zh.yml', 'w', encoding='utf-8') as f:
    yaml.dump(zh, f, allow_unicode=True, default_flow_style=False, sort_keys=False)
print('     Done.')
"

echo ""
echo "=== 3. Building English site (site/) ==="
mkdocs build --clean

echo ""
echo "=== 4. Building Chinese site (site/zh/) ==="
mkdocs build -f mkdocs-zh.yml --clean

echo ""
echo "=== Done ==="
echo "  English: site/index.html"
echo "  Chinese: site/zh/index.html"
