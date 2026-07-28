# Bart Blog Demo Web App

This is the database-backed proof of concept for the Ehrman blog category, topic, and keyword search demo.

The app stores only post metadata:

- title
- URL
- author
- date
- description
- subject areas
- categories
- topics
- secondary keywords

The actual post content remains on `ehrmanblog.org`. Post titles link to the original blog URLs.

## Local Run

From the repository root:

```powershell
python -m webapp.import_data
python -m webapp.app
```

Then open:

```text
http://127.0.0.1:8000
```

## Render Setup

Create a Render **Web Service**, not a Static Site.

Suggested settings:

- Name: `bart-blog-demo`
- Branch: `main`
- Runtime: Python
- Build command: `pip install -r requirements.txt && python -m webapp.import_data`
- Start command: `gunicorn webapp.app:application --bind 0.0.0.0:$PORT`
- Database: none for version 1; SQLite is generated from the JSON files

The same settings are also included in `render.yaml`.

## Python/PHP Search Parity Harness

The parity harness captures the Python application's search behavior before a
PHP port. Generated cases and baseline artifacts are written under
`tests/parity/artifacts/`, which is ignored by Git.

Generate and capture the local smoke suite:

```powershell
python -B scripts\search_parity.py generate --profile smoke
python -B scripts\search_parity.py capture
```

Generate the full deterministic suite:

```powershell
python -B scripts\search_parity.py generate --profile full
```

Compare two captures:

```powershell
python -B scripts\search_parity.py compare expected.jsonl.gz actual.jsonl.gz
```

Remote testing uses `POST /api/parity/batch`. The endpoint returns `404` unless
`EHRMAN_PARITY_TEST_TOKEN` is configured on the service. The caller must send
the same value in the `X-Ehrman-Parity-Token` request header. Do not commit the
token.

Capture a configured Render service by placing the token in the local
environment and supplying its base URL:

```powershell
python -B scripts\search_parity.py capture --base-url https://example.onrender.com
```

See `docs/python_php_search_parity_test_plan.md` for the behavioral contract,
coverage, artifacts, and acceptance criteria.
