#!/usr/bin/env python3
"""Fetch WordPress documentation and aggregate into memories/wordpress-lllm-developer.txt.

Sources:
  - developer.wordpress.org (Block Editor, Themes, REST API, Common APIs, etc.)

Output: memories/wordpress-lllm-developer.txt (relative to wordpress-agent/)

Run from wordpress-agent/: python scripts/fetch_wordpress_docs.py
"""

from __future__ import annotations

import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]  # wordpress-agent/
OUTPUT = ROOT / "memories" / "wordpress-lllm-developer.txt"

# Curated list of handbook URLs (developer.wordpress.org supports ?output_format=md)
# Excludes Plugin Handbook per user request
DOC_URLS = [
    # Block Editor
    "https://developer.wordpress.org/block-editor/?output_format=md",
    "https://developer.wordpress.org/block-editor/getting-started/?output_format=md",
    "https://developer.wordpress.org/block-editor/getting-started/devenv/?output_format=md",
    "https://developer.wordpress.org/block-editor/getting-started/quick-start-guide/?output_format=md",
    "https://developer.wordpress.org/block-editor/getting-started/fundamentals/?output_format=md",
    "https://developer.wordpress.org/block-editor/getting-started/faq/?output_format=md",
    "https://developer.wordpress.org/block-editor/how-to-guides/block-api/?output_format=md",
    "https://developer.wordpress.org/block-editor/reference-guides/block-api/?output_format=md",
    "https://developer.wordpress.org/block-editor/reference-guides/core-blocks/?output_format=md",
    "https://developer.wordpress.org/block-editor/explanations/architecture/key-concepts/?output_format=md",
    "https://developer.wordpress.org/block-editor/explanations/architecture/data-flow/?output_format=md",
    # Themes
    "https://developer.wordpress.org/themes/?output_format=md",
    "https://developer.wordpress.org/themes/getting-started/?output_format=md",
    "https://developer.wordpress.org/themes/getting-started/what-is-a-theme/?output_format=md",
    "https://developer.wordpress.org/themes/getting-started/tools-and-setup/?output_format=md",
    "https://developer.wordpress.org/themes/getting-started/quick-start-guide/?output_format=md",
    "https://developer.wordpress.org/themes/core-concepts/?output_format=md",
    "https://developer.wordpress.org/themes/basics/?output_format=md",
    "https://developer.wordpress.org/themes/advanced-topics/?output_format=md",
    "https://developer.wordpress.org/themes/getting-started/theme-security/?output_format=md",
    # REST API
    "https://developer.wordpress.org/rest-api/?output_format=md",
    "https://developer.wordpress.org/rest-api/key-concepts/?output_format=md",
    "https://developer.wordpress.org/rest-api/using-the-rest-api/?output_format=md",
    "https://developer.wordpress.org/rest-api/extending-the-rest-api/?output_format=md",
    "https://developer.wordpress.org/rest-api/reference/?output_format=md",
    "https://developer.wordpress.org/rest-api/authentication/?output_format=md",
    # Common APIs (Hooks, Filters - no plugin-specific)
    "https://developer.wordpress.org/apis/?output_format=md",
    "https://developer.wordpress.org/apis/hooks/?output_format=md",
    "https://developer.wordpress.org/apis/hooks/action-reference/?output_format=md",
    "https://developer.wordpress.org/apis/hooks/filter-reference/?output_format=md",
    "https://developer.wordpress.org/apis/options/?output_format=md",
    "https://developer.wordpress.org/apis/settings/?output_format=md",
    # Advanced Administration
    "https://developer.wordpress.org/advanced-administration/?output_format=md",
    "https://developer.wordpress.org/advanced-administration/before-install/?output_format=md",
    "https://developer.wordpress.org/advanced-administration/upgrade/?output_format=md",
    "https://developer.wordpress.org/advanced-administration/debug/?output_format=md",
    # Coding Standards
    "https://developer.wordpress.org/coding-standards/?output_format=md",
    "https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/?output_format=md",
    "https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/?output_format=md",
    # WP-CLI
    "https://developer.wordpress.org/cli/commands/?output_format=md",
    "https://developer.wordpress.org/cli/commands/core/?output_format=md",
    "https://developer.wordpress.org/cli/commands/core/download/?output_format=md",
    "https://developer.wordpress.org/cli/commands/db/?output_format=md",
]


def _fetch(url: str, timeout: int = 30) -> str:
    req = urllib.request.Request(
        url=url,
        headers={"User-Agent": "personal-ki-agents-wordpress-doc-fetcher/1.0"},
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read()
        encoding = resp.headers.get_content_charset() or "utf-8"
    return raw.decode(encoding, errors="replace")


def _clean_content(text: str) -> str:
    """Remove boilerplate, normalize whitespace."""
    # Drop "Back to top" links
    text = re.sub(r"\[ Back to top\]\([^)]+\)", "", text)
    # Remove excessive blank lines
    text = re.sub(r"\n{4,}", "\n\n\n", text)
    return text.strip()


def main() -> int:
    print("Fetching WordPress documentation...")
    parts: list[str] = []
    seen = set()
    failed = 0

    for url in DOC_URLS:
        try:
            content = _fetch(url)
            clean = _clean_content(content)
            if not clean or len(clean) < 100:
                print(f"  [SKIP] {url} (too short)")
                continue
            # Dedupe by URL
            if url in seen:
                continue
            seen.add(url)
            parts.append(f"\n\n---\nurl: {url}\n---\n\n{clean}")
            print(f"  [OK] {url[:70]}...")
        except Exception as e:
            failed += 1
            print(f"  [FAIL] {url}: {e}")

    if not parts:
        print("[ERROR] No content fetched.")
        return 0

    output_text = (
        "# WordPress Developer Documentation – LLM Reference\n\n"
        "Aggregated from developer.wordpress.org.\n"
        "Excludes: Plugin Handbook.\n\n"
        "Use this as reference for WordPress development: Block Editor, Themes, REST API, "
        "Common APIs (Hooks/Filters), Advanced Administration, Coding Standards, WP-CLI.\n"
        + "".join(parts)
    )

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(output_text, encoding="utf-8")
    print(f"\n[SUCCESS] Wrote {len(output_text):,} chars to {OUTPUT}")
    if failed:
        print(f"[WARN] {failed} failed fetches")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
