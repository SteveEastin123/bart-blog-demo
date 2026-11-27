# Blog Search User Guide

Publisher-facing instructions for adding posts, reviewing metadata, managing
Subject Areas/Categories/Topics, and reading AI Analytics. This guide is separate
from the installation and developer documentation.

## Deliverables

- `../../output/pdf/ehrman_blog_search_user_guide.pdf`: sharing/printing copy.
- `ehrman_blog_search_user_guide.html`: self-contained, editable HTML, including images.
- `guide.json`: canonical wording and page structure for rebuilding both formats.
- `images/`: screenshots captured from the isolated test site on November 27, 2026.

Include the PDF and HTML in the developer handoff's user-documentation materials.
The source content, images, and builder remain in the repository for maintenance.
The runtime plugin does not depend on these files.

## Maintenance

Update `guide.json` and, when needed, replace screenshots with captures of the
actual interface. Then run:

```text
python scripts/build_blog_search_user_guide.py
```

Dependencies: ReportLab and Pillow; the builder embeds Arial when available on
Windows and falls back to PDF standard Helvetica elsewhere. Re-render every PDF
page after changes and inspect for clipping, unreadable images, and incorrect
page references. The builder rejects page overflow instead of silently spilling
content onto an unplanned page. PDF page breaks are intentional; the HTML is
responsive and scrollable. For print distribution, use the supplied PDF.

The HTML can be edited directly, but rebuilding replaces it. Make lasting changes
in `guide.json` so the PDF and HTML remain consistent.

## Screenshot Provenance

Screenshots reflect source commit `795acd09d01f41359ad6fe1f5a6b39cc0d504a8c`,
with the Manage Topics screenshot refreshed for the November 27 change hiding
the redundant Search button when live suggestions are available.
The review example uses a simulated metadata response, explicitly labeled as
illustrative in the guide; it is not a quality assessment of AI-generated content.
Analytics show an empty reporting history. No paid AI calls were made, no Render
changes were made, and temporary demonstration posts were removed from the
isolated test database. The normal local demo's posts were not modified.

Before the production handoff, replace site-specific screenshots if the
development team's theme/editor integration changes these screens.
