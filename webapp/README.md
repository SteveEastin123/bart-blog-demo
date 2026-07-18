# Bart Blog Demo Web App

This is the database-backed proof of concept for the Ehrman blog category, topic, and keyword search demo.

The app stores only post metadata:

- title
- URL
- author
- date
- description
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
