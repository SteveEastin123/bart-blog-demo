"""Build the publisher guide PDF and self-contained HTML from the same content."""

import base64
import html
import json
import shutil
from pathlib import Path

from PIL import Image as PillowImage
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Image, ListFlowable, ListItem, Paragraph, Table, TableStyle

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs/user-guide"
PDF = ROOT / "output/pdf/ehrman_blog_publisher_guide.pdf"
HTML = SOURCE / "ehrman_blog_publisher_guide.html"
PLUGIN_DOCS = ROOT / "wordpress-plugin/ehrman-blog-discovery/docs"
WIDTH, HEIGHT = 612, 792
MARGIN = 46
CONTENT = WIDTH - 2 * MARGIN
INK = colors.HexColor("#222b32")
MUTED = colors.HexColor("#586570")
ACCENT = colors.HexColor("#176b72")
RULE = colors.HexColor("#cbd4d8")
HEADING_SPACE_BEFORE = 18
HEADING_SPACE_AFTER = 6


def register_fonts():
    fonts = Path("C:/Windows/Fonts")
    if (fonts / "arial.ttf").exists():
        for name, filename in [("Guide", "arial.ttf"), ("Guide-Bold", "arialbd.ttf"), ("Guide-Italic", "ariali.ttf")]:
            pdfmetrics.registerFont(TTFont(name, str(fonts / filename)))
        pdfmetrics.registerFontFamily("Guide", normal="Guide", bold="Guide-Bold", italic="Guide-Italic", boldItalic="Guide-Bold")
        return "Guide", "Guide-Bold"
    return "Helvetica", "Helvetica-Bold"


FONT, BOLD = register_fonts()
STYLES = {
    "section": ParagraphStyle("Section", fontName=BOLD, fontSize=24, leading=29, textColor=ACCENT),
    "task": ParagraphStyle("Task", fontName=BOLD, fontSize=18, leading=22, textColor=INK),
    "body": ParagraphStyle("Body", fontName=FONT, fontSize=10.5, leading=14.5, textColor=INK),
    "h2": ParagraphStyle("Heading", fontName=BOLD, fontSize=13, leading=17, textColor=ACCENT),
    "intro": ParagraphStyle("Intro", fontName=FONT, fontSize=11, leading=15, textColor=MUTED),
    "caption": ParagraphStyle("Caption", fontName=FONT, fontSize=8.5, leading=11, textColor=MUTED),
    "note": ParagraphStyle("Note", fontName=FONT, fontSize=9.5, leading=13, textColor=MUTED),
    "cell": ParagraphStyle("Cell", fontName=FONT, fontSize=10, leading=13.5, textColor=INK),
    "head": ParagraphStyle("TableHeading", fontName=BOLD, fontSize=10, leading=13, textColor=ACCENT),
}


def paragraph(text, style="body"):
    return Paragraph(text, STYLES[style])


def list_flow(items, numbered, width):
    return ListFlowable(
        [ListItem(paragraph(text), spaceAfter=7) for text in items],
        bulletType="1" if numbered else "bullet", start="1" if numbered else None,
        leftIndent=17, bulletFontName=FONT, bulletFontSize=10, bulletColor=ACCENT,
        bulletOffsetY=0, spaceAfter=0,
    )


def table_flow(block, width):
    data = [[paragraph(value, "head") for value in block["headers"]]]
    data.extend([[paragraph(value, "cell") for value in row] for row in block["rows"]])
    table = Table(data, colWidths=[width * part for part in block["widths"]], hAlign="LEFT")
    table.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 7), ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 7), ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#eef4f4")),
        ("LINEBELOW", (0, 0), (-1, -1), 0.4, RULE),
    ]))
    return table


def image_flow(filename, width, height):
    source = SOURCE / "images" / filename
    with PillowImage.open(source) as image:
        w, h = image.size
    scale = min(width / w, height / h)
    return Image(str(source), w * scale, h * scale, hAlign="LEFT")


def pdf_blocks(block, width):
    kind = block["type"]
    if kind in ("p", "h2", "note"):
        style = "task" if "id" in block else ("body" if kind == "p" else kind)
        return [(paragraph(block["text"], style), HEADING_SPACE_AFTER if kind == "h2" else 8)]
    if kind in ("steps", "bullets"):
        return [(list_flow(block["items"], kind == "steps", width), 4)]
    if kind == "table":
        return [(table_flow(block, width), 10)]
    if kind == "image":
        return [(image_flow(block["file"], width, block["height"]), 5), (paragraph(block["caption"], "caption"), 12)]
    if kind == "split":
        image_width = width * 0.57
        text_width = width - image_width - 18
        left = [image_flow(block["file"], image_width, 430), paragraph(block["caption"], "caption")]
        right = [list_flow(block["items"], True, text_width)]
        table = Table([[left, right]], colWidths=[image_width + 12, text_width + 6])
        table.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ("RIGHTPADDING", (0, 0), (0, 0), 12),
            ("RIGHTPADDING", (1, 0), (1, 0), 0),
            ("TOPPADDING", (0, 0), (-1, -1), 0),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
        ]))
        return [(table, 14)]
    raise ValueError(f"Unknown guide block: {kind}")


def contents_sections(guide):
    """Derive both contents formats from the printed headings and page order."""
    sections = []
    for page_number, page in enumerate(guide["pages"], 2):
        if not sections or sections[-1]["title"] != page["section"]:
            sections.append({"title": page["section"], "target": "section-" + page["id"],
                             "page": page_number, "children": []})
        if page["title"] != page["section"]:
            sections[-1]["children"].append({"title": page["title"], "target": page["id"], "page": page_number})
        for block in page["blocks"]:
            if block["type"] == "h2" and "id" in block:
                sections[-1]["children"].append({"title": block["text"], "target": block["id"], "page": page_number})
    return sections


def pdf_footer(c, guide, page_number):
    c.setStrokeColor(RULE)
    c.line(MARGIN, 39, WIDTH - MARGIN, 39)
    c.setFillColor(MUTED)
    c.setFont(FONT, 8)
    c.drawString(MARGIN, 25, guide["title"] + " | " + guide["date"])
    c.drawRightString(WIDTH - MARGIN, 25, f'{page_number} / {len(guide["pages"]) + 1}')


def pdf_contents(c, guide):
    c.bookmarkPage("contents")
    c.addOutlineEntry("Table of Contents", "contents", 0)
    y = 766
    for text, style in ((guide["title"], "task"), ("Table of Contents", "section")):
        flow = paragraph(html.escape(text), style)
        _, height = flow.wrap(CONTENT, HEIGHT)
        flow.drawOn(c, MARGIN, y - height)
        y -= height + 16
    rows = [[paragraph("Section", "head"), paragraph("Page", "head")]]
    styles = [
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LINEBELOW", (0, 0), (-1, 0), 0.6, RULE),
    ]
    page_style = ParagraphStyle("ContentsPage", parent=STYLES["cell"], alignment=2)
    for section in contents_sections(guide):
        for level, item in [(0, section), *((1, child) for child in section["children"])]:
            row_number = len(rows)
            title = html.escape(item["title"])
            if level == 0:
                title = f'<b>{title}</b>'
                styles.append(("TOPPADDING", (0, row_number), (-1, row_number), 11))
            else:
                styles.append(("LEFTPADDING", (0, row_number), (0, row_number), 16))
            rows.append([
                paragraph(f'<a href="#{item["target"]}">{title}</a>', "cell"),
                Paragraph(f'<a href="#{item["target"]}">{item["page"]}</a>', page_style),
            ])
    table = Table(rows, colWidths=[CONTENT - 42, 42])
    table.setStyle(TableStyle(styles))
    _, height = table.wrapOn(c, CONTENT, HEIGHT)
    if y - height < 51:
        raise ValueError("Table of Contents exceeds one page")
    table.drawOn(c, MARGIN, y - height)
    pdf_footer(c, guide, 1)
    c.showPage()
    return {"page": 1, "free_space_pt": round(y - height - 48, 1)}


def build_pdf(guide):
    PDF.parent.mkdir(parents=True, exist_ok=True)
    c = canvas.Canvas(str(PDF), pagesize=(WIDTH, HEIGHT), pageCompression=1)
    c.setTitle(guide["title"])
    c.setAuthor("Ehrman Blog Discovery")
    c.setSubject(guide["subtitle"])
    measurements = [pdf_contents(c, guide)]
    previous_section = None
    for number, page in enumerate(guide["pages"], 2):
        c.bookmarkPage(page["id"])
        if page["section"] != previous_section:
            section_key = "section-" + page["id"]
            c.bookmarkPage(section_key)
            c.addOutlineEntry(page["section"], section_key, 0)
        if page["title"] != page["section"]:
            c.addOutlineEntry(page["title"], page["id"], 1)
        previous_section = page["section"]
        y = 766
        section = paragraph(html.escape(page["section"]), "section")
        _, height = section.wrap(CONTENT, HEIGHT)
        section.drawOn(c, MARGIN, y - height)
        y -= height + 9
        c.setStrokeColor(RULE)
        c.setLineWidth(0.6)
        c.line(MARGIN, y, WIDTH - MARGIN, y)
        y -= 12
        if page["title"] != page["section"]:
            title = paragraph(html.escape(page["title"]), "task")
            _, height = title.wrap(CONTENT, HEIGHT)
            title.drawOn(c, MARGIN, y - height)
            y -= height + 12
        flows = []
        if page.get("intro"):
            flows.append((paragraph(page["intro"], "intro"), 13, None))
        for block in page["blocks"]:
            for index, (flow, gap) in enumerate(pdf_blocks(block, CONTENT)):
                flows.append((flow, gap, block if index == 0 else None))
        previous_gap = None
        for flow, gap, block in flows:
            # Collapse the preceding block's gap into the heading's own spacing.
            if block and block["type"] == "h2" and previous_gap is not None:
                y -= max(0, HEADING_SPACE_BEFORE - previous_gap)
            _, height = flow.wrapOn(c, CONTENT, HEIGHT)
            if y - height < 51:
                raise ValueError(f"Page {number} '{page['title']}' overflows by {51 - (y - height):.1f} pt")
            if block and "id" in block:
                c.bookmarkHorizontalAbsolute(block["id"], y)
                c.addOutlineEntry(block["text"], block["id"], 1)
            flow.drawOn(c, MARGIN, y - height)
            y -= height + gap
            previous_gap = gap
        measurements.append({"page": number, "free_space_pt": round(y - 48, 1)})
        pdf_footer(c, guide, number)
        c.showPage()
    c.save()
    return measurements


def image_html(filename, caption):
    encoded = base64.b64encode((SOURCE / "images" / filename).read_bytes()).decode("ascii")
    return f'<figure><img src="data:image/png;base64,{encoded}" alt="{html.escape(caption)}"><figcaption>{html.escape(caption)}</figcaption></figure>'


def html_block(block, heading_level=4):
    kind = block["type"]
    if kind == "h2":
        anchor = f' id="{html.escape(block["id"], quote=True)}"' if "id" in block else ""
        return f'<h{heading_level}{anchor}>{block["text"]}</h{heading_level}>'
    if kind in ("p", "note"):
        return f'<p class="{kind}">{block["text"]}</p>'
    if kind in ("steps", "bullets"):
        tag = "ol" if kind == "steps" else "ul"
        return f'<{tag}>' + "".join(f'<li>{item}</li>' for item in block["items"]) + f'</{tag}>'
    if kind == "image":
        return image_html(block["file"], block["caption"])
    if kind == "split":
        return '<div class="split">' + image_html(block["file"], block["caption"]) + html_block({"type": "steps", "items": block["items"]}, heading_level) + '</div>'
    if kind == "table":
        headings = ''.join(f'<th scope="col">{item}</th>' for item in block["headers"])
        rows = ''.join('<tr>' + ''.join(f'<td>{item}</td>' for item in row) + '</tr>' for row in block["rows"])
        label = f' aria-label="{html.escape(block["label"], quote=True)}"' if "label" in block else ""
        return f'<table{label}><thead><tr>{headings}</tr></thead><tbody>{rows}</tbody></table>'
    raise ValueError(kind)


def html_contents(guide):
    groups = []
    for section in contents_sections(guide):
        children = ''.join(f'<li><a href="#{child["target"]}">{html.escape(child["title"])}</a></li>'
                           for child in section["children"])
        groups.append(f'<li><a href="#{section["target"]}">{html.escape(section["title"])}</a><ol>{children}</ol></li>')
    return '<nav id="contents" aria-label="Table of Contents"><h2>Table of Contents</h2><ol class="contents">' + ''.join(groups) + '</ol></nav>'


def build_html(guide):
    sections = []
    previous_section = None
    for number, page in enumerate(guide["pages"], 1):
        new_section = page["section"] != previous_section
        if new_section:
            if previous_section is not None:
                sections.append('</section>')
            sections.append(f'<section id="{page["id"]}"><h2 id="section-{page["id"]}">{html.escape(page["section"])}</h2>')
        article_id = '' if new_section else f' id="{page["id"]}"'
        body = '' if number == 1 else f'<h3 class="task-title">{html.escape(page["title"])}</h3>'
        if page.get("intro"):
            body += f'<p class="intro">{page["intro"]}</p>'
        body += ''.join(html_block(block, 3 if number == 1 else 4) for block in page["blocks"])
        if number != 1:
            body += '<p class="back"><a href="#contents">Back to contents</a></p>'
        sections.append(f'<article{article_id}>{body}</article>')
        previous_section = page["section"]
    sections.append('</section>')
    css = """
    :root { color-scheme: light; font-family: Arial, sans-serif; color: #222b32; background: #fff; }
    * { box-sizing: border-box; letter-spacing: 0; }
    body { margin: 0; font-size: 17px; line-height: 1.6; }
    main { max-width: 900px; padding: 0 30px; margin: auto; }
    header { padding-top: 32px; }
    nav { padding: 30px 0; scroll-margin-top: 15px; }
    .contents, .contents ol { list-style: none; padding: 0; }
    .contents > li { margin: 18px 0; padding: 0; }
    .contents > li > a { font-weight: bold; }
    .contents ol { margin-top: 6px; padding-left: 22px; }
    .contents ol li { margin: 5px 0; padding: 0; }
    section { padding: 48px 0 40px; border-bottom: 1px solid #cbd4d8; scroll-margin-top: 15px; }
    article { scroll-margin-top: 15px; }
    article + article { padding-top: 36px; margin-top: 40px; border-top: 1px solid #cbd4d8; }
    h1 { font-size: 32px; line-height: 1.2; margin: 0; }
    h2 { font-size: 30px; line-height: 1.2; color: #176b72; margin: 0 0 24px; padding-bottom: 14px; border-bottom: 1px solid #cbd4d8; scroll-margin-top: 15px; }
    h3 { font-size: 24px; line-height: 1.3; margin: 36px 0 12px; scroll-margin-top: 15px; }
    .task-title { margin-top: 0; }
    h4 { font-size: 19px; line-height: 1.3; color: #176b72; margin: 36px 0 12px; }
    article h3 + *, article h4 + * { margin-top: 0; }
    article h3 + :is(ul, ol) > li:first-child, article h4 + :is(ul, ol) > li:first-child { margin-top: 0; }
    .intro { color: #586570; font-size: 18px; }
    p { margin: 12px 0; } li { margin: 10px 0; padding-left: 4px; }
    a { color: #176b72; text-underline-offset: 3px; }
    a:focus-visible { outline: 2px solid #176b72; outline-offset: 3px; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 16px; }
    th, td { padding: 11px 12px; border-bottom: 1px solid #cbd4d8; text-align: left; vertical-align: top; overflow-wrap: normal; }
    th { color: #176b72; background: #eef4f4; }
    figure { margin: 24px 0; } img { max-width: 100%; height: auto; display: block; border: 1px solid #d7dfe2; }
    figcaption { margin-top: 8px; font-size: 14px; color: #586570; }
    .note { font-size: 15px; color: #586570; border-top: 1px solid #cbd4d8; padding-top: 12px; margin-top: 22px; }
    .split { display: grid; grid-template-columns: 46% 1fr; gap: 25px; align-items: start; }
    .split ol { margin-top: 17px; padding-left: 22px; } .back { font-size: 14px; margin-top: 26px; }
    footer { padding: 28px 0; color: #586570; font-size: 14px; }
    @media(max-width: 620px) { main { padding: 0 18px; } .split { display: block; } th, td { padding: 9px 7px; } }
    @media print { main { max-width: none; padding: 0; } nav { break-after: page; } section:not(:first-of-type), article + article { break-before: page; } section, article + article { padding: 0; margin: 0; border: 0; } h2, h3, h4 { break-after: avoid; } .back { display: none; } body { font-size: 11pt; } img { max-height: 4.5in; } }
    """
    HTML.write_text('<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                    + '<title>' + guide["title"] + '</title><style>' + css + '</style></head><body><main>'
                    + '<header><h1>' + html.escape(guide["title"]) + '</h1></header>'
                    + html_contents(guide)
                    + ''.join(sections) + '<footer>Edition: ' + guide["date"] + '. Ehrman Blog Discovery.</footer></main></body></html>\n', encoding="utf-8")


def main():
    guide = json.loads((SOURCE / "guide.json").read_text(encoding="utf-8"))
    measurements = build_pdf(guide)
    build_html(guide)
    PLUGIN_DOCS.mkdir(parents=True, exist_ok=True)
    for artifact in (PDF, HTML):
        shutil.copyfile(artifact, PLUGIN_DOCS / artifact.name)
    print(json.dumps({"pdf": str(PDF), "html": str(HTML), "plugin_docs": str(PLUGIN_DOCS), "layout": measurements}, indent=2))


if __name__ == "__main__":
    main()
