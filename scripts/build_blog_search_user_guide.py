"""Build the publisher guide PDF and self-contained HTML from the same content."""

import base64
import html
import json
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
PDF = ROOT / "output/pdf/ehrman_blog_search_user_guide.pdf"
HTML = SOURCE / "ehrman_blog_search_user_guide.html"
WIDTH, HEIGHT = 612, 792
MARGIN = 46
CONTENT = WIDTH - 2 * MARGIN
INK = colors.HexColor("#222b32")
MUTED = colors.HexColor("#586570")
ACCENT = colors.HexColor("#176b72")
RULE = colors.HexColor("#cbd4d8")


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


def toc_flow(pages, width):
    rows = []
    for page_number, page in enumerate(pages[1:], 2):
        rows.append([f'<link href="#{page["id"]}">{page["title"]}</link>', str(page_number)])
    table = table_flow({"headers": ["In this guide", "Page"], "rows": rows, "widths": [0.91, 0.09]}, width)
    table.setStyle(TableStyle([
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
    ]))
    return table


def pdf_blocks(block, pages, width):
    kind = block["type"]
    if kind in ("p", "h2", "note"):
        return [(paragraph(block["text"], "body" if kind == "p" else kind), 8 if kind != "h2" else 6)]
    if kind in ("steps", "bullets"):
        return [(list_flow(block["items"], kind == "steps", width), 4)]
    if kind == "table":
        return [(table_flow(block, width), 10)]
    if kind == "toc":
        return [(toc_flow(pages, width), 10)]
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


def build_pdf(guide):
    PDF.parent.mkdir(parents=True, exist_ok=True)
    c = canvas.Canvas(str(PDF), pagesize=(WIDTH, HEIGHT), pageCompression=1)
    c.setTitle(guide["title"])
    c.setAuthor("Ehrman Blog Discovery")
    c.setSubject(guide["subtitle"])
    measurements = []
    for number, page in enumerate(guide["pages"], 1):
        c.bookmarkPage(page["id"])
        c.addOutlineEntry(page["title"], page["id"], 0)
        c.setFillColor(ACCENT)
        c.setFont(BOLD, 9)
        c.drawString(MARGIN, 758, page["section"])
        c.setStrokeColor(RULE)
        c.setLineWidth(0.6)
        c.line(MARGIN, 746, WIDTH - MARGIN, 746)
        title = Paragraph(page["title"], ParagraphStyle("Title", fontName=BOLD, fontSize=24, leading=28, textColor=INK))
        _, height = title.wrap(CONTENT, HEIGHT)
        y = 728 - height
        title.drawOn(c, MARGIN, y)
        y -= 12
        flows = []
        if page.get("intro"):
            flows.append((paragraph(page["intro"], "intro"), 13))
        for block in page["blocks"]:
            flows.extend(pdf_blocks(block, guide["pages"], CONTENT))
        for flow, gap in flows:
            _, height = flow.wrapOn(c, CONTENT, HEIGHT)
            if y - height < 51:
                raise ValueError(f"Page {number} '{page['title']}' overflows by {51 - (y - height):.1f} pt")
            flow.drawOn(c, MARGIN, y - height)
            y -= height + gap
        measurements.append({"page": number, "free_space_pt": round(y - 48, 1)})
        c.setStrokeColor(RULE)
        c.line(MARGIN, 39, WIDTH - MARGIN, 39)
        c.setFillColor(MUTED)
        c.setFont(FONT, 8)
        c.drawString(MARGIN, 25, "Ehrman Blog Discovery | " + guide["date"])
        c.drawRightString(WIDTH - MARGIN, 25, str(number) + " / " + str(len(guide["pages"])))
        c.showPage()
    c.save()
    return measurements


def image_html(filename, caption):
    encoded = base64.b64encode((SOURCE / "images" / filename).read_bytes()).decode("ascii")
    return f'<figure><img src="data:image/png;base64,{encoded}" alt="{html.escape(caption)}"><figcaption>{html.escape(caption)}</figcaption></figure>'


def html_block(block, pages):
    kind = block["type"]
    if kind == "h2":
        return f'<h2>{block["text"]}</h2>'
    if kind in ("p", "note"):
        return f'<p class="{kind}">{block["text"]}</p>'
    if kind in ("steps", "bullets"):
        tag = "ol" if kind == "steps" else "ul"
        return f'<{tag}>' + "".join(f'<li>{item}</li>' for item in block["items"]) + f'</{tag}>'
    if kind == "image":
        return image_html(block["file"], block["caption"])
    if kind == "split":
        return '<div class="split">' + image_html(block["file"], block["caption"]) + html_block({"type": "steps", "items": block["items"]}, pages) + '</div>'
    if kind == "table":
        headings = ''.join(f'<th scope="col">{item}</th>' for item in block["headers"])
        rows = ''.join('<tr>' + ''.join(f'<td>{item}</td>' for item in row) + '</tr>' for row in block["rows"])
        return f'<table><thead><tr>{headings}</tr></thead><tbody>{rows}</tbody></table>'
    if kind == "toc":
        return '<nav aria-label="Contents"><h2>In this guide</h2><ol start="2">' + ''.join(f'<li><a href="#{p["id"]}">{p["title"]}</a></li>' for p in pages[1:]) + '</ol></nav>'
    raise ValueError(kind)


def build_html(guide):
    sections = []
    for page in guide["pages"]:
        body = f'<p class="section-label">{page["section"]}</p><h1>{page["title"]}</h1>'
        if page.get("intro"):
            body += f'<p class="intro">{page["intro"]}</p>'
        body += ''.join(html_block(block, guide["pages"]) for block in page["blocks"])
        body += '<p class="back"><a href="#start">Back to contents</a></p>'
        sections.append(f'<section id="{page["id"]}">{body}</section>')
    css = """
    :root { color-scheme: light; font-family: Arial, sans-serif; color: #222b32; background: #fff; }
    * { box-sizing: border-box; letter-spacing: 0; }
    body { margin: 0; font-size: 17px; line-height: 1.6; }
    main { max-width: 900px; padding: 0 30px; margin: auto; }
    section { padding: 42px 0; border-bottom: 1px solid #cbd4d8; scroll-margin-top: 15px; }
    h1 { font-size: 32px; line-height: 1.2; margin: 12px 0 20px; }
    h2 { font-size: 21px; line-height: 1.3; color: #176b72; margin: 28px 0 10px; }
    .section-label { color: #176b72; font-weight: bold; font-size: 13px; padding-bottom: 12px; border-bottom: 1px solid #cbd4d8; }
    .intro { color: #586570; font-size: 18px; }
    p { margin: 12px 0; } li { margin: 10px 0; padding-left: 4px; }
    a { color: #176b72; text-underline-offset: 3px; }
    a:focus-visible { outline: 2px solid #176b72; outline-offset: 3px; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 16px; }
    th, td { padding: 11px 12px; border-bottom: 1px solid #cbd4d8; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
    th { color: #176b72; background: #eef4f4; }
    figure { margin: 24px 0; } img { max-width: 100%; height: auto; display: block; border: 1px solid #d7dfe2; }
    figcaption { margin-top: 8px; font-size: 14px; color: #586570; }
    .note { font-size: 15px; color: #586570; border-top: 1px solid #cbd4d8; padding-top: 12px; margin-top: 22px; }
    .split { display: grid; grid-template-columns: 46% 1fr; gap: 25px; align-items: start; }
    .split ol { margin-top: 17px; padding-left: 22px; } .back { font-size: 14px; margin-top: 26px; }
    footer { padding: 28px 0; color: #586570; font-size: 14px; }
    @media(max-width: 620px) { main { padding: 0 18px; } h1 { font-size: 27px; } .split { display: block; } th, td { padding: 9px 7px; } }
    @media print { main { max-width: none; padding: 0; } section { break-before: page; padding: 0; border: 0; } .back { display: none; } body { font-size: 11pt; } img { max-height: 4.5in; } }
    """
    HTML.write_text('<!doctype html>\n<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                    + '<title>' + guide["title"] + '</title><style>' + css + '</style></head><body><main>'
                    + ''.join(sections) + '<footer>Edition: ' + guide["date"] + '. Ehrman Blog Discovery.</footer></main></body></html>\n', encoding="utf-8")


def main():
    guide = json.loads((SOURCE / "guide.json").read_text(encoding="utf-8"))
    measurements = build_pdf(guide)
    build_html(guide)
    print(json.dumps({"pdf": str(PDF), "html": str(HTML), "layout": measurements}, indent=2))


if __name__ == "__main__":
    main()
