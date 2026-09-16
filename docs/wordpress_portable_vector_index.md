# WordPress Portable Vector Index

## Purpose

`data/index/ehrman_post_embeddings.jsonl.gz` is the portable Ask AI 2 semantic
index. It carries one precomputed `text-embedding-3-small` vector for each post
eligible for semantic search, so a fresh WordPress/MySQL installation does not
need to recreate the initial index through paid API calls.

The package represents the selected title-and-summary index. It does not
contain full post text. The retired topic, alias, and secondary-keyword vector
experiment is preserved only in historical evaluation files.

## Commands

Export a complete current index:

```bash
wp ehrman-discovery embeddings export \
  --file=/secure/ehrman_post_embeddings.jsonl.gz \
  --allow-root \
  --path=/var/www/html
```

Import a package after the authoritative JSON has been imported:

```bash
wp ehrman-discovery embeddings import \
  --file=/secure/ehrman_post_embeddings.jsonl.gz \
  --allow-root \
  --path=/var/www/html
```

Both commands require an explicit `.jsonl.gz` path. Export refuses an
incomplete index. Import is all-or-nothing and idempotent: unchanged rows are
skipped, valid missing or stale rows are restored, and obsolete content-vector
rows are removed.

## Package Contract

The gzip stream contains one JSON object per line. The first line is a versioned
header containing the format name, format version, content basis, embedding
model, dimensions, record count, creation time, and plugin version. Every
following line contains:

- WordPress post ID
- Canonical title-and-summary SHA-256 content hash
- Embedding model and dimension count
- Base64-encoded little-endian float32 vector bytes
- Euclidean vector norm
- Original database update time in UTC

Before any database change, import verifies the header and every record. It
rejects unsupported versions, mismatched models or dimensions, unknown or
ineligible posts, stale hashes, duplicate post IDs, invalid base64, incorrect
byte lengths, non-finite values, incorrect norms, invalid timestamps, and a
package that omits any currently eligible post.

## Refresh Procedure

After canonical post metadata changes:

1. Import the updated JSON into the local production-equivalent WordPress site.
2. Run `wp ehrman-discovery embeddings` to build only missing or stale vectors.
3. Confirm `wp ehrman-discovery status` reports every eligible vector current.
4. Run `wordpress-production/export-vector-package.ps1` to export, copy, and
   atomically replace `data/index/ehrman_post_embeddings.jsonl.gz`.
5. Run the complete production acceptance suite before committing.

The bundled package is immutable image content at
`/opt/ehrman-import/ehrman_post_embeddings.jsonl.gz`; it is never exposed under
the Apache document root.
