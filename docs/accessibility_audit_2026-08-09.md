# Accessibility Audit

Date: August 9, 2026  
Scope: WordPress/MySQL demo plugin 0.4.27 and the standalone HTML demo

## Standard and Method

The automated audit used axe-core 4.10.2 with the WCAG 2.0 A/AA, WCAG 2.1
A/AA, and WCAG 2.2 AA rule sets. It covered representative home, keyword
search, search-results, subject-area, category, topic-post, and structure-review
views.

Each view was checked at these viewport widths:

- 1,440 pixels (desktop)
- 390 pixels (mobile)
- 320 pixels (narrow reflow)

The audit also included manual browser-automation checks for keyboard focus,
visible focus indicators, category-selector state, autocomplete state, arrow-key
navigation, Enter-key selection, description-mode radio controls, and horizontal
page overflow.

## Issues Corrected

1. Keyword autocomplete inputs exposed `aria-expanded` without an explicit
   combobox role. The inputs now use `role="combobox"` with their existing
   listbox relationships and state attributes.
2. Empty WordPress keyword slots had a 4.17:1 text contrast ratio. Their text
   color was darkened within the existing palette to meet WCAG AA.
3. Hidden standalone description tooltips widened the document by 12 pixels on
   a 390-pixel mobile viewport. Their narrow-screen width now stays within the
   available content area.
4. Standalone keyword inputs now retain an explicit accessible name as the
   active keyword position changes.

## Results

- 33 automated page-and-viewport checks completed
- 0 failed pages
- 0 axe WCAG violations after correction
- 0 pages with horizontal overflow after correction
- Keyboard category selection, autocomplete navigation, term selection, and
  description controls passed in both implementations
- Standalone demo validation passed
- WordPress Coding Standards and PHPStan passed
- WordPress/MySQL integration verification passed, including REST status,
  imported data counts, and representative search behavior

## Remaining Production Checks

Automated testing cannot certify complete accessibility. Before production
release, test the integrated site theme with current versions of NVDA with
Chrome or Edge and VoiceOver with Safari. Include keyboard-only review at 200%
and 400% browser zoom, and confirm that any theme-level header, navigation,
cookie banner, account controls, and third-party components remain accessible.
The external Bart Ehrman Blog post pages are outside this plugin's interface
scope and require their own audit.
