# Remaining-post section package

This offline preparation workflow extends the preserved 500-post pilot with
300-word sections for the remaining eligible posts. It does not install an
Ask AI 3 index, import database rows, change production search, or run evaluations.

The existing paragraph-aware splitter is reused unchanged: target 300 words,
nearby paragraph endings within 20%, and 60-word overlap. Every stored source word
must be covered without rewriting the text. Eligibility is unchanged: a current
title/summary vector, stored text of at least 100 words, and no Ignore topic.
Coverage checks confirm stored-text coverage, not that every download is complete.
Exceptions and their IDs are retained in the private prepared dataset.

`text-embedding-3-small` with 512 dimensions embeds title-plus-passage inputs.
Identical inputs share a vector; the original 300-word pilot vectors are reused
when possible. API credentials use the existing private environment configuration
and never appear in generated files. The script calls only `/v1/embeddings` and
requires explicit paid-call authorization. It saves one response per fixed batch,
validates input hashes and response indices, and resumes without repurchasing
completed batches. A $0.20 recorded-usage guard applies; ambiguous network failures
stop rather than automatically repeat possibly charged requests.
If a network failure or interruption occurs during an API call, do not simply
rerun the command: first reconcile the possibly accepted request with provider
usage. The cached-receipt resume mechanism cannot account for a response that was
never received or saved. The $0.20 guard is not a provider-side spending cap.

Run from the repository root with Python 3.11+:

```powershell
python -B -m unittest discover -s experiments/section-search/expansion -p 'test_*.py'
python -B experiments/section-search/expansion/build.py prepare
# Only this command can make paid API calls, after user authorization:
python -B experiments/section-search/expansion/build.py embed --approve-api
python -B experiments/section-search/expansion/build.py package
python -B experiments/section-search/expansion/build.py verify
python -B experiments/section-search/expansion/audit.py --offline
```

Outputs use run identity `remaining-300-512-2026-10-18`. Public manifests, usage
totals, and checksums are in `../runs/<run>/`. Full text, raw API receipts, and
vectors stay in the Git-ignored `../private/<run>/` directory. All section-search
experiments remain excluded from Render images by the existing `.dockerignore`.
No original file is overwritten. A different build must use a new run identity.

The private `package/` contains:

- `posts.jsonl.gz`: post ID, title, canonical URL, text hash, and word count.
- `sections-300-512.jsonl.gz`: post ID, zero-based section number and word offsets,
  unchanged passage text, input hash, and a base64 little-endian float32 vector.
- `manifest.json`: model, dimensions, section settings, counts, file hashes,
  provenance, and the separately preserved 500-post pilot package reference.

The package includes only remaining posts. Future integration must explicitly
combine it with the pilot package after checking source versions and post IDs.
That integration, runtime performance testing, and relevance comparisons are
separate work. The text files are private source material, not public web assets.

Verification replays the saved API receipts, checks 512 finite normalized values
per vector, verifies exact title-plus-passage hashes and float32 packing, checks
complete stored-word coverage and overlap, and hashes prior experiments, canonical
index files, raw post text, and production plugin files before and after.
The separate final audit also validates every package manifest field and requires
the exact two-file checksum inventory and matching preserved-pilot provenance.
