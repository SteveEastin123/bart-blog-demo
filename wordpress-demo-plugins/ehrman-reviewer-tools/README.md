# Ehrman Reviewer Tools (Demo Only)

This optional companion plugin preserves the evaluation tools in the local and
Render demos. It is **not included in the development-company production plugin**.
The production plugin has no dependency on this directory.

## Features

- Both Browse Topics outlines and the complete Categories and Topics indexes.
- Expand/collapse, topic filtering, description preferences and tooltips.
- CSV and PDF downloads generated locally in the browser.
- Reviewer Tools menu link and evaluation note in the companion demo theme.
- The existing `/structure-review/` URL and `ehrman_discovery_page_structure_review`
  page option; existing page content and identifiers are preserved.

The add-on reads the production plugin's browsing data. It creates no tables,
changes no taxonomy or vector records, adds no REST endpoints, and makes no AI
calls. Pages are public for demo reviewers but marked noindex/nofollow; these
directives are not access controls.

## Installation and Removal

Activate Ehrman Blog Discovery 0.10.4+ first, then activate Ehrman Reviewer Tools.
WordPress 6.5+, PHP 8.1+ are required, matching the production plugin.

```sh
wp plugin activate ehrman-reviewer-tools
```

The demo image contains both plugin directories. `initialize-ehrman-wordpress`
activates the companion on new demo setups. For an existing Render demo, run
the activation command once after manually deploying the updated image; no
database reimport is needed. Nothing here deploys Render automatically.

Deactivation removes the menu link and evaluation note and returns the managed
review page to draft, without deleting it or any discovery data. Reactivation
publishes that same page. Unrelated content at the reserved page slug is never
overwritten. Deactivate before deleting the add-on from an existing demo.
Production installations should receive only `wordpress-plugin/ehrman-blog-discovery`,
not this companion directory or a database clone containing demo pages/options.

## Source and Tests

`src/Plugin.php` owns lifecycle, shortcode, optional assets and demo theme hooks.
`src/Structure_Review_Renderer.php` owns the existing review markup.
`assets/src/` owns review JavaScript and CSS. At build time the add-on reuses a
few pure/shared frontend helpers from the production sources; its generated
bundle has no runtime module imports. The dependency goes only from the add-on
to production, never the reverse.

From the repository root:

```sh
npm run build:wordpress-assets
npm run check:wordpress-assets
node wordpress-production/tests/frontend-assets-ui.cjs
pwsh ./wordpress-production/verify-reviewer-isolation.ps1 -BrowserChecks
```

The isolation check briefly disables and then restores Reviewer Tools. The full
production verification also runs lifecycle tests using rolled-back fixtures.
Both plugins are scanned with PHPCS and PHPStan in CI. The public browser test
checks all four review views, downloads and responsive layout without AI calls.
