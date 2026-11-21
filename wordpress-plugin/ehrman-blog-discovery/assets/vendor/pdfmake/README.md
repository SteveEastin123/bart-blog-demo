# PDF Export Dependency

Unmodified browser builds from pdfmake **0.3.13** (MIT):
https://registry.npmjs.org/pdfmake/-/pdfmake-0.3.13.tgz

Upstream source: https://github.com/bpampuch/pdfmake/tree/0.3.13
Documentation: https://pdfmake.github.io/docs/0.3/

The archive SHA-1 was verified against npm metadata:
`3708018cf5fe3f0c11dcd4245e2d160d3859fcf4`.

- `pdfmake.min.js`: upstream PDF generator browser bundle.
- `vfs_fonts.js`: upstream embedded Roboto fonts; Apache 2.0 license included.
- `LICENSE.txt`: pdfmake MIT license.
- `ROBOTO-LICENSE.txt`: https://github.com/googlefonts/roboto/blob/main/LICENSE

These assets load only on the administrator Export tab. No CDN, external PDF
service, Node runtime, or Composer install is needed at runtime. Exported names
are supplied as plain text, never HTML, SVG, file paths, or remote URLs.

To upgrade, replace both builds from one pinned upstream release, retain its
licenses, and run organization-export PHP/browser tests plus PDF visual checks.
Do not edit the vendor bundles manually.
