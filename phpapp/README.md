# PHP Search Demo

This directory contains a PHP 8.4 implementation of the Bart Blog search
demo. It reads the same SQLite database and serves the same browse, topic,
category, search, autocomplete, health, and protected parity routes as the
Python application in `webapp/`.

The Python application remains the reference implementation. Keep the PHP
deployment separate until the standard 500-case parity suite and browser
checks pass against both deployed services.

## Local development

Build the SQLite database first:

```powershell
python -B -m webapp.import_data
```

Start the PHP development server from the repository root:

```powershell
$env:EHRMAN_PARITY_TEST_TOKEN = "local-parity-token"
php -S 127.0.0.1:8091 -t phpapp/public phpapp/router.php
```

Open `http://127.0.0.1:8091`. The parity endpoint is unavailable unless
`EHRMAN_PARITY_TEST_TOKEN` is set.

## Docker

The multi-stage image builds SQLite from the authoritative JSON files in a
temporary Python stage. The final runtime contains PHP, Apache, the generated
database, and the browser assets; it does not contain Python or the private raw
post corpus.

```powershell
docker build -f phpapp/Dockerfile -t bart-blog-demo-php .
docker run --rm -p 10000:10000 `
  -e EHRMAN_PARITY_TEST_TOKEN=local-parity-token `
  bart-blog-demo-php
```

## Render comparison service

Create a second Render web service named `bart-blog-demo-php`; do not replace
the existing Python service. Configure it as a Docker service with:

- repository: the same repository used by the Python demo
- branch: `main`
- Dockerfile: `phpapp/Dockerfile`
- Docker build context: repository root (`.`)
- health check: `/healthz`
- environment variable: `EHRMAN_PARITY_TEST_TOKEN` with the same temporary
  parity token used by the Python service

`render.yaml.example` records the equivalent Blueprint configuration without
altering the active root `render.yaml`.

## Parity validation

Generate the fixed 500-case suite once, then capture both services with the
same case file:

```powershell
python -B scripts/search_parity.py generate --profile standard

python -B scripts/search_parity.py capture `
  --base-url https://bart-blog-demo.onrender.com `
  --output tests/parity/artifacts/python-render.jsonl.gz `
  --digests tests/parity/artifacts/python-render-digests.jsonl `
  --batch-size 25 --retries 5 --resume

python -B scripts/search_parity.py capture `
  --base-url https://bart-blog-demo-php.onrender.com `
  --output tests/parity/artifacts/php-render.jsonl.gz `
  --digests tests/parity/artifacts/php-render-digests.jsonl `
  --batch-size 25 --retries 5 --resume

python -B scripts/search_parity.py compare `
  tests/parity/artifacts/python-render.jsonl.gz `
  tests/parity/artifacts/php-render.jsonl.gz `
  --report tests/parity/artifacts/python-vs-php.json
```

The comparison must report zero behavioral differences before any production
cutover. A manifest-only difference in runtime or commit metadata is expected.
