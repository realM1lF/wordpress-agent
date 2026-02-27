#!/usr/bin/env python3
"""Fetch WooCommerce developer documentation from the official llms-full.txt.

Source: https://developer.woocommerce.com/docs/llms-full.txt
        (official LLM-optimized Markdown export by WooCommerce/Automattic)

Note: llms-full.txt does NOT include the WC REST API docs or Code Reference.
      The REST API docs are fetched separately from woocommerce.github.io.

Output: memories/woocommerce-llm-developer.txt (relative to wordpress-agent/)

Run from wordpress-agent/: python scripts/fetch_woocommerce_docs.py
"""

from __future__ import annotations

import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]  # wordpress-agent/
OUTPUT = ROOT / "memories" / "woocommerce-llm-developer.txt"

LLMS_FULL_URL = "https://developer.woocommerce.com/docs/llms-full.txt"

REST_API_URL = "https://woocommerce.github.io/woocommerce-rest-api-docs/#introduction"
REST_API_MAX_CHARS = 150_000


def _fetch(url: str, timeout: int = 60) -> str:
    req = urllib.request.Request(
        url=url,
        headers={"User-Agent": "personal-ki-agents-woocommerce-doc-fetcher/1.0"},
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read()
        encoding = resp.headers.get_content_charset() or "utf-8"
    return raw.decode(encoding, errors="replace")


def _strip_html(html: str) -> str:
    """Basic HTML-to-text for the REST API single-page docs."""
    text = re.sub(r"<script[^>]*>.*?</script>", "", html, flags=re.S)
    text = re.sub(r"<style[^>]*>.*?</style>", "", html, flags=re.S)
    text = re.sub(r"<!--.*?-->", "", text, flags=re.S)
    text = re.sub(r"<(h[1-6])[^>]*>", lambda m: "\n\n" + "#" * int(m.group(1)[1]) + " ", text)
    text = re.sub(r"<br\s*/?>", "\n", text)
    text = re.sub(r"<li[^>]*>", "\n- ", text)
    text = re.sub(r"<pre[^>]*>", "\n```\n", text)
    text = re.sub(r"</pre>", "\n```\n", text)
    text = re.sub(r"<[^>]+>", "", text)
    text = re.sub(r"&nbsp;", " ", text)
    text = re.sub(r"&amp;", "&", text)
    text = re.sub(r"&lt;", "<", text)
    text = re.sub(r"&gt;", ">", text)
    text = re.sub(r"\n{4,}", "\n\n\n", text)
    return text.strip()


def main() -> int:
    print("Fetching WooCommerce developer documentation...")

    # 1. Fetch official llms-full.txt
    print(f"  Fetching {LLMS_FULL_URL} ...")
    try:
        llms_content = _fetch(LLMS_FULL_URL)
    except Exception as e:
        print(f"  [FATAL] Could not fetch llms-full.txt: {e}")
        return 1

    if len(llms_content) < 1000:
        print(f"  [FATAL] llms-full.txt too small ({len(llms_content)} chars), aborting.")
        return 1

    print(f"  [OK] llms-full.txt: {len(llms_content):,} chars")

    # 2. Fetch WC REST API docs (not included in llms-full.txt)
    rest_api_section = ""
    print(f"  Fetching REST API docs from {REST_API_URL} ...")
    try:
        raw_html = _fetch(REST_API_URL)
        rest_text = _strip_html(raw_html)
        if len(rest_text) > REST_API_MAX_CHARS:
            rest_text = rest_text[:REST_API_MAX_CHARS] + (
                f"\n\n... [truncated at {REST_API_MAX_CHARS:,} chars, "
                f"full docs: {REST_API_URL}]"
            )
        rest_api_section = (
            f"\n\n---\n\n"
            f"# WooCommerce REST API Reference\n"
            f"Source: {REST_API_URL}\n\n"
            f"{rest_text}"
        )
        print(f"  [OK] REST API docs: {len(rest_text):,} chars")
    except Exception as e:
        print(f"  [WARN] Could not fetch REST API docs: {e}")
        print(f"         Continuing without REST API section.")

    # 3. Write output
    output_text = llms_content + rest_api_section

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(output_text, encoding="utf-8")
    print(f"\n[SUCCESS] Wrote {len(output_text):,} chars to {OUTPUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
