# Ehrman Blog Publisher Guide

Publisher-facing instructions for adding posts, reviewing metadata, managing
Subject Areas/Categories/Topics, reading AI Analytics, and using Settings and
Info & Documentation. This guide is separate from installation and developer documentation.

A linked Table of Contents lists all six numbered sections and their task headings.
Section 1, Introduction, includes 1.1 Quick Guide and 1.2 What Is Search Metadata?
Sections 2-6 cover Adding a Post, Post Metadata, Managing Topics, AI Analytics, and
Settings, Info & Documentation. Each task has a matching subsection number, such
as 2.1 or 2.2. Smaller in-task labels remain unnumbered.

The Quick Guide links WordPress screens to their instructions; the contents and
PDF bookmarks navigate the document's full structure. Contents entries are
generated from the same headings in both formats, with page numbers in the PDF.
Main section headings are larger than task titles and subheadings, and HTML
groups related tasks under each section heading. Adding a Post explains both
modes; Post Metadata owns detailed review and follow-up steps.

## Deliverables

- `../../output/pdf/ehrman_blog_publisher_guide.pdf`: sharing/printing copy.
- `ehrman_blog_publisher_guide.html`: self-contained, editable HTML, including images.
- `guide.json`: canonical wording and page structure for rebuilding both formats.
- `images/`: screenshots captured from local sites on November 27, 2026.

Include the PDF and HTML in the developer handoff's user-documentation materials.
The source content, images, and builder remain in the repository for maintenance.
The builder also copies both finished formats into
`wordpress-plugin/ehrman-blog-discovery/docs/`. Include that directory in the
installable plugin: **Blog Search > Info & Documentation** links to these bundled
copies using the installed plugin URL. No Render or local-demo URL is required.
They are static, public-readable documentation with no credentials or live data;
the administrative information page itself requires `manage_options`.

## Maintenance

Update `guide.json` and, when needed, replace screenshots with captures of the
actual interface. Then run:

```text
python scripts/build_blog_publisher_guide.py
```

Rebuilding refreshes both the sharing copies and the plugin copies. Do not edit
the bundled copies independently. Verify packaging with:

```text
python -m unittest discover -s wordpress-production/tests -p test_publisher_guide.py
```

Dependencies: ReportLab and Pillow; the builder embeds Arial when available on
Windows and falls back to PDF standard Helvetica elsewhere. Re-render every PDF
page after changes and inspect for clipping, unreadable images, and incorrect
page references. The builder rejects page overflow instead of silently spilling
content onto an unplanned page. PDF page breaks are intentional; the HTML is
responsive and scrollable. For print distribution, use the supplied PDF.

The HTML can be edited directly, but rebuilding replaces it. Make lasting changes
in `guide.json` so the PDF and HTML remain consistent.

The Manage Topics section separates finding an item from creating one. It covers
focus-to-browse suggestions, live filtering and keyboard selection, Browse All
and View All Matches, the fallback Search button, relationship pickers and saves,
optional Subject Area links, zero-post Topics, description editing, deletion
safeguards, and the scope of PDF/CSV exports.

## Screenshot Provenance

Screenshots reflect source commit `795acd09d01f41359ad6fe1f5a6b39cc0d504a8c`,
with the Manage Topics screenshot refreshed for the November 27 change hiding
the redundant Search button when live suggestions are available.
The Settings controls image was captured read-only from the normal local demo
at commit `e8e9b65`; its selected modes are illustrative, not recommended defaults.
All other images originated from the isolated test site.
The review example uses a simulated metadata response, explicitly labeled as
illustrative in the guide; it is not a quality assessment of AI-generated content.
Analytics show an empty reporting history. No paid AI calls were made, no Render
changes were made, and temporary demonstration posts were removed from the
isolated test database. The normal local demo's posts were not modified.

Before the production handoff, replace site-specific screenshots if the
development team's theme/editor integration changes these screens.
