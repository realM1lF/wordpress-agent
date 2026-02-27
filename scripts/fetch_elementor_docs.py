#!/usr/bin/env python3
"""Fetch Elementor documentation and aggregate into memories/elementor-llm-developer.txt.

Sources:
  - elementor.com/llms.txt (Produktuebersicht, Add-ons, Themes)
  - raw.githubusercontent.com (Markdown-Quellcode der Developer-Docs)

Output: memories/elementor-llm-developer.txt (relative to wordpress-agent/)

Run from wordpress-agent/: python scripts/fetch_elementor_docs.py
"""

from __future__ import annotations

import json
import re
import sys
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]  # wordpress-agent/
OUTPUT = ROOT / "memories" / "elementor-llm-developer.txt"

LLMS_TXT_URL = "https://elementor.com/llms.txt"
GITHUB_TREE_URL = "https://api.github.com/repos/elementor/elementor-developers-docs/git/trees/master?recursive=1"
RAW_BASE = "https://raw.githubusercontent.com/elementor/elementor-developers-docs/master"
FETCH_DELAY = 0.2  # seconds between requests


def _fetch(url: str, timeout: int = 30) -> str:
    req = urllib.request.Request(
        url=url,
        headers={"User-Agent": "personal-ki-agents-elementor-doc-fetcher/1.0"},
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read()
        encoding = resp.headers.get_content_charset() or "utf-8"
    return raw.decode(encoding, errors="replace")


def _get_doc_urls() -> list[tuple[str, str]]:
    """Fetch doc URL list from GitHub. Returns [(raw_url, doc_path)]."""
    try:
        data = json.loads(_fetch(GITHUB_TREE_URL))
    except Exception as e:
        print(f"  [FATAL] Could not fetch GitHub tree: {e}")
        sys.exit(1)

    items = []
    for node in data.get("tree", []):
        path = node.get("path", "")
        if path.endswith(".md") and path.startswith("src/") and "README" not in path:
            raw_url = f"{RAW_BASE}/{path}"
            doc_path = path[4:-3]  # src/addons/foo.md -> addons/foo
            items.append((raw_url, doc_path))

    return sorted(items, key=lambda x: x[1])


def _clean_content(text: str) -> str:
    """Remove boilerplate, normalize whitespace."""
    text = re.sub(r"\[ Back to top\]\([^)]+\)", "", text)
    text = re.sub(r"\n{4,}", "\n\n\n", text)
    return text.strip()


def main() -> int:
    print("Fetching Elementor documentation...")

    # 1. Fetch elementor.com/llms.txt (Produktuebersicht)
    print(f"  Fetching {LLMS_TXT_URL} ...")
    try:
        llms_content = _fetch(LLMS_TXT_URL)
        llms_content = _clean_content(llms_content)
    except Exception as e:
        print(f"  [WARN] Could not fetch llms.txt: {e}")
        llms_content = ""

    if llms_content:
        print(f"  [OK] llms.txt: {len(llms_content):,} chars")

    # 2. Get doc URL list from GitHub (raw Markdown)
    print("  Fetching doc URL list from GitHub...")
    doc_items = _get_doc_urls()
    print(f"  [OK] Found {len(doc_items)} doc pages")

    # 3. Fetch developer docs (raw Markdown from GitHub)
    parts: list[str] = []
    failed = 0
    for i, (raw_url, doc_path) in enumerate(doc_items):
        try:
            content = _fetch(raw_url)
            clean = _clean_content(content)
            if not clean or len(clean) < 30:
                continue
            parts.append(f"\n\n---\ndoc: {doc_path}\n---\n\n{clean}")
            if (i + 1) % 50 == 0:
                print(f"  [OK] Fetched {i + 1}/{len(doc_items)} ...")
        except Exception as e:
            failed += 1
            if failed <= 5:
                print(f"  [FAIL] {doc_path}: {e}")
        time.sleep(FETCH_DELAY)

    if not parts:
        print("[ERROR] No developer docs fetched.")
        return 1

    # 4. Combine and write
    header = (
        "# Elementor Developer Documentation – LLM Reference\n\n"
        "Aggregated from elementor.com/llms.txt and GitHub (elementor/elementor-developers-docs).\n"
        "Use for Elementor addons, widgets, controls, hooks, forms, themes, CLI.\n"
    )
    llms_section = (
        f"\n\n---\n# Elementor Product Overview (elementor.com/llms.txt)\n---\n\n{llms_content}\n"
        if llms_content
        else ""
    )
    output_text = header + llms_section + "".join(parts)

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(output_text, encoding="utf-8")
    print(f"\n[SUCCESS] Wrote {len(output_text):,} chars to {OUTPUT}")
    if failed:
        print(f"[WARN] {failed} failed fetches")
    return 0


if __name__ == "__main__":
    sys.exit(main())
