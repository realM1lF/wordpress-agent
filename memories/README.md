# WordPress Agent Memories

Reference files loaded into the agent's vector store for semantic search.

## wordpress-lllm-developer.txt

Aggregated WordPress developer documentation (Block Editor, Themes, REST API, Common APIs, etc.).

**Update:** Run from wordpress-agent directory:

```bash
cd wordpress-agent
python scripts/fetch_wordpress_docs.py
```

Then reload memories in the WordPress admin (or via the agent's memory sync).
