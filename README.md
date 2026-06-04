# Bart Ehrman Blog Indexing

This workspace is for building a local index of Ehrman Blog posts.

The raw full-text export is intentionally kept out of git. Keep repositories
private unless you are only publishing code and non-sensitive metadata.

## Workflow

1. Put the temporary member credentials in `.ehrman_credentials.env`.
2. Visit each post URL and save clean post metadata plus full text.
3. Produce summaries and candidate tags.
4. Review the candidate tags and normalize posts against a controlled tag set.

## Files

- `scripts/ehrman_http_indexer.py`: scraper/indexer for the logged-in site.
- `.ehrman_credentials.env.example`: template for the temporary login file.
- `data/raw/archive_months.json`: discovered monthly archive URLs and visible counts.
- `data/raw/post_urls.json`: local generated post URLs and archive-source metadata.
- `data/raw/posts.jsonl`: local full extracted post records, one JSON object per line.
- `data/index/posts_index.json`: summary/tag index generated from raw posts.
- `data/index/posts_index.csv`: spreadsheet-friendly version of the same index.

## Run

Create `.ehrman_credentials.env`:

```ini
EHRMAN_USERNAME=...
EHRMAN_PASSWORD=...
```

Run a small authenticated pilot:

```powershell
python -B scripts\ehrman_http_indexer.py --reset --limit-months 1 --limit-posts 3
```

Run the full archive:

```powershell
python -B scripts\ehrman_http_indexer.py --reset
```
