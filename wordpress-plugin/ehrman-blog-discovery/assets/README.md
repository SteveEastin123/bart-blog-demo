# Discovery Frontend Assets

Edit the source files under `src/`, not the generated `js/discovery.js` or
`css/discovery.css`. WordPress still enqueues those two generated files through
`Assets::enqueue()`. The public handles, localized `EhrmanDiscovery` configuration,
DOM hooks, storage keys, and REST request formats are unchanged.

## JavaScript Ownership

| Source in `src/js/` | Responsibility |
| --- | --- |
| `discovery.js` | Startup and delegated page events |
| `shared.js` | Configuration, normalization, small DOM helpers |
| `search-state.js` | Selected terms, modes, categories, request/browser URLs |
| `keyword-search.js` | Keyword and Browse Topics controls, suggestions, AJAX search |
| `results.js` | Post lists, relevance groups, sorting and pagination |
| `ask-ai.js` | Question submission and refined/broader result switching |
| `feedback.js` | Reader feedback controls and submission |
| `descriptions.js` | Description preferences and tooltips |
| `status.js` | Status refresh control |

These are standard ES modules with explicit imports. They have no circular
dependencies. The build emits one classic-script IIFE without adding browser
globals or requiring module support from the WordPress theme.

## CSS Ownership

`src/css/discovery.css` lists imports in cascade order. Shared/browse styling,
search panels and controls, Ask AI, results, utilities, and
responsive overrides each have a source file. The small `search-panel.css` file
preserves the original position of that rule before the Ask AI question styles.
Keep the import order and responsive overrides intact unless deliberately
changing appearance. The output has no runtime CSS imports.

## Build and Check

From the repository root, with Node.js 22 and npm installed:

```sh
npm ci
npm run build:wordpress-assets
npm run check:wordpress-assets
```

Commit source and generated assets together. The check rebuilds in memory and
fails on stale generated files, syntax errors, warnings, import cycles, or failed
frontend unit tests. GitHub Actions runs it before WordPress regression tests.
The bundler is pinned in `package-lock.json` and is development-only. Neither
Node.js nor npm is required on WordPress or Render; ship the generated assets
with the plugin. Bump the plugin version when releasing changed runtime assets
so WordPress invalidates browser caches.

With the local demo running, also run:

```sh
node wordpress-production/tests/search-page-ui.cjs
node wordpress-production/tests/ask-ai-retirement-ui.cjs
node wordpress-production/tests/frontend-assets-ui.cjs
```

The last script checks all Reviewer Tools views and exports, descriptions and
tooltips, Ask AI result groups and pagination, feedback retry, and status refresh.
All AI requests are mocked. For a visual-only/refactoring change, run both
`search-page-ui.cjs` and `frontend-assets-ui.cjs` with `--capture-before` before
refreshing WordPress, then with `--compare-before` afterward. These compare DOM
and screenshots; the frontend script also compares the complete CSS cascade and
CSV/PDF bytes. Capture and compare exports on the same day because PDF headings
include the date. Baselines are local scratch files under `.tmp/`.

The post-editor ingestion assets remain independent and are not part of this
public frontend build.

Reviewer Tools now lives outside this production plugin in the optional
`wordpress-demo-plugins/ehrman-reviewer-tools` companion. The repository build
also builds its assets when that directory is present. Use
`node scripts/build_wordpress_assets.cjs --production-only` to build only the
handoff assets. A production-only source checkout does not need the companion.
When comparing a pre-extraction baseline, `frontend-assets-ui.cjs` accepts
`--compare-before --reviewer-extraction`: it verifies all remaining production
CSS rules, plus the existing DOM, screenshot and export comparisons.
