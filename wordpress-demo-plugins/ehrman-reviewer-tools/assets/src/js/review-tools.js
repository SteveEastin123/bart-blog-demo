import { normalized } from "../../../../../wordpress-plugin/ehrman-blog-discovery/assets/src/js/shared.js";
import { descriptionMode } from "../../../../../wordpress-plugin/ehrman-blog-discovery/assets/src/js/descriptions.js";

export function directChild(element, selector) {
  return Array.from(element?.children || []).find((child) => child.matches(selector)) || null;
}

function compactText(element) {
  return String(element?.textContent || "").trim().replace(/\s+/g, " ");
}

function reviewName(section) {
  const heading = section.matches(".ebd-review-topic")
    ? directChild(section, ".ebd-review-topic-row")
    : directChild(section, "summary");
  return compactText(heading?.querySelector(".ebd-review-name > span:last-child"));
}

function reviewDescription(section) {
  return compactText(directChild(section, ".ebd-review-description"));
}

function reviewMeta(section) {
  const heading = section.matches(".ebd-review-topic")
    ? directChild(section, ".ebd-review-topic-row")
    : directChild(section, "summary");
  return compactText(heading?.querySelector(".ebd-review-meta"));
}

function reviewPostCount(section) {
  const match = reviewMeta(section).match(/([\d,]+)\s+posts?\b/i);
  return match ? match[1].replace(/,/g, "") : "";
}

function reviewViewLabel(root) {
  return compactText(root.querySelector(".ebd-review-path.is-active")) || "Category and Topic Review";
}

function reviewCsvRows(root, tree) {
  const view = reviewViewLabel(root);
  const rows = [];
  if (tree.classList.contains("is-topic-index")) {
    tree.querySelectorAll("[data-ebd-review-topic-item]").forEach((topic) => {
      rows.push([
        view,
        "",
        "",
        "",
        String(topic.dataset.ebdReviewCategories || ""),
        "",
        "",
        reviewName(topic),
        reviewDescription(topic),
        reviewPostCount(topic),
      ]);
    });
    return rows;
  }
  const addCategory = (category, area = null) => {
    const topics = Array.from(directChild(category, ".ebd-review-topic-list")?.children || [])
      .filter((item) => item.matches(".ebd-review-topic"));
    const base = [
      view,
      area ? reviewName(area) : "",
      area ? reviewDescription(area) : "",
      area ? reviewPostCount(area) : "",
      reviewName(category),
      reviewDescription(category),
      reviewPostCount(category),
    ];

    if (!topics.length) {
      rows.push([...base, "", "", ""]);
      return;
    }
    topics.forEach((topic) => {
      rows.push([...base, reviewName(topic), reviewDescription(topic), reviewPostCount(topic)]);
    });
  };

  const areas = Array.from(tree.children).filter((item) => item.matches(".ebd-review-area"));
  if (areas.length) {
    areas.forEach((area) => {
      const categories = Array.from(directChild(area, ".ebd-review-categories")?.children || [])
        .filter((item) => item.matches(".ebd-review-category"));
      categories.forEach((category) => addCategory(category, area));
    });
  } else {
    Array.from(tree.children)
      .filter((item) => item.matches(".ebd-review-category"))
      .forEach((category) => addCategory(category));
  }
  return rows;
}

function csvCell(value) {
  return `"${String(value ?? "").replace(/"/g, '""')}"`;
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function downloadReviewCsv(root, tree) {
  const headers = [
    "Review View",
    "Subject Area",
    "Subject Area Description",
    "Subject Area Post Count",
    "Category",
    "Category Description",
    "Category Post Count",
    "Topic",
    "Topic Description",
    "Topic Post Count",
  ];
  const csv = [headers, ...reviewCsvRows(root, tree)]
    .map((row) => row.map(csvCell).join(","))
    .join("\r\n");
  const filename = `${normalized(reviewViewLabel(root)).replace(/\s+/g, "-") || "category-topic-review"}.csv`;
  downloadBlob(new Blob(["\uFEFF", csv], { type: "text/csv;charset=utf-8" }), filename);
}

function pdfAscii(value) {
  return String(value || "")
    .replace(/[\u2018\u2019]/g, "'")
    .replace(/[\u201c\u201d]/g, '"')
    .replace(/[\u2013\u2014]/g, "-")
    .replace(/\u2022/g, "-")
    .replace(/\u2026/g, "...")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^\x20-\x7e]/g, "");
}

function pdfEscape(value) {
  return pdfAscii(value)
    .replace(/\\/g, "\\\\")
    .replace(/\(/g, "\\(")
    .replace(/\)/g, "\\)");
}

function wrapPdfText(value, availableWidth, fontSize) {
  const text = pdfAscii(value).trim();
  if (!text) return [];
  const maxCharacters = Math.max(18, Math.floor(availableWidth / (fontSize * 0.52)));
  const words = text.split(/\s+/);
  const lines = [];
  let line = "";

  words.forEach((word) => {
    const chunks = [];
    for (let index = 0; index < word.length; index += maxCharacters) {
      chunks.push(word.slice(index, index + maxCharacters));
    }
    (chunks.length ? chunks : [word]).forEach((chunk) => {
      const candidate = line ? `${line} ${chunk}` : chunk;
      if (candidate.length > maxCharacters && line) {
        lines.push(line);
        line = chunk;
      } else {
        line = candidate;
      }
    });
  });
  if (line) lines.push(line);
  return lines;
}

function reviewPdfEntries(tree, includeDescriptions) {
  const entries = [];
  const addSection = (section, level) => {
    const meta = reviewMeta(section);
    const styles = [
      { indent: 0, size: 16, font: "F2", color: "0.49 0.11 0.09", before: 12, after: 3 },
      { indent: 20, size: 13, font: "F2", color: "0.12 0.12 0.12", before: 9, after: 2 },
      { indent: 42, size: 11, font: "F1", color: "0.12 0.12 0.12", before: 4, after: 2 },
    ];
    const style = styles[level];
    entries.push({ ...style, text: `${reviewName(section)}${meta ? ` (${meta})` : ""}` });
    if (includeDescriptions && reviewDescription(section)) {
      entries.push({
        text: reviewDescription(section),
        indent: style.indent + 12,
        size: 9,
        font: "F1",
        color: "0.49 0.11 0.09",
        before: 0,
        after: 4,
      });
    }
  };
  const addCategory = (category) => {
    addSection(category, 1);
    Array.from(directChild(category, ".ebd-review-topic-list")?.children || [])
      .filter((item) => item.matches(".ebd-review-topic"))
      .forEach((topic) => addSection(topic, 2));
  };

  if (tree.classList.contains("is-topic-index")) {
    tree.querySelectorAll("[data-ebd-review-topic-item]").forEach((topic) => addSection(topic, 1));
    return entries;
  }

  const areas = Array.from(tree.children).filter((item) => item.matches(".ebd-review-area"));
  if (areas.length) {
    areas.forEach((area) => {
      addSection(area, 0);
      Array.from(directChild(area, ".ebd-review-categories")?.children || [])
        .filter((item) => item.matches(".ebd-review-category"))
        .forEach(addCategory);
    });
  } else {
    Array.from(tree.children)
      .filter((item) => item.matches(".ebd-review-category"))
      .forEach(addCategory);
  }
  return entries;
}

function reviewPdfPages(entries) {
  const pages = [[]];
  let pageIndex = 0;
  let y = 710;
  const newPage = () => {
    pages.push([]);
    pageIndex += 1;
    y = 738;
  };

  entries.forEach((entry) => {
    const lineHeight = entry.size + 4;
    const lines = wrapPdfText(entry.text, 532 - entry.indent, entry.size);
    if (y - entry.before - lineHeight < 50) newPage();
    y -= entry.before;
    lines.forEach((line) => {
      if (y - lineHeight < 50) newPage();
      pages[pageIndex].push({ ...entry, text: line, x: 40 + entry.indent, y });
      y -= lineHeight;
    });
    y -= entry.after;
  });
  return pages;
}

function pdfTextCommand(line) {
  return `BT /${line.font} ${line.size} Tf ${line.color} rg 1 0 0 1 ${line.x} ${line.y} Tm (${pdfEscape(line.text)}) Tj ET\n`;
}

function buildReviewPdf(root, tree) {
  const view = reviewViewLabel(root);
  const includeDescriptions = descriptionMode(root) === "always";
  const pages = reviewPdfPages(reviewPdfEntries(tree, includeDescriptions));
  const objects = [];
  objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
  objects[2] = `<< /Type /Pages /Kids [${pages.map((unused, index) => `${5 + index * 2} 0 R`).join(" ")}] /Count ${pages.length} >>`;
  objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
  objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

  pages.forEach((lines, index) => {
    const pageNumber = index + 1;
    const pageObject = 5 + index * 2;
    const contentObject = pageObject + 1;
    let content = "";
    if (0 === index) {
      content += pdfTextCommand({ text: "Category and Topic Review", font: "F2", size: 20, color: "0.49 0.11 0.09", x: 40, y: 758 });
      content += pdfTextCommand({ text: `${view} - ${new Date().toLocaleDateString()}`, font: "F1", size: 10, color: "0.35 0.35 0.35", x: 40, y: 738 });
    } else {
      content += pdfTextCommand({ text: view, font: "F2", size: 10, color: "0.35 0.35 0.35", x: 40, y: 760 });
    }
    lines.forEach((line) => {
      content += pdfTextCommand(line);
    });
    content += pdfTextCommand({ text: `Page ${pageNumber} of ${pages.length}`, font: "F1", size: 9, color: "0.35 0.35 0.35", x: 500, y: 24 });
    objects[pageObject] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ${contentObject} 0 R >>`;
    objects[contentObject] = `<< /Length ${content.length} >>\nstream\n${content}endstream`;
  });

  let pdf = "%PDF-1.4\n%----\n";
  const offsets = [0];
  for (let index = 1; index < objects.length; index += 1) {
    offsets[index] = pdf.length;
    pdf += `${index} 0 obj\n${objects[index]}\nendobj\n`;
  }
  const xrefOffset = pdf.length;
  pdf += `xref\n0 ${objects.length}\n0000000000 65535 f \n`;
  for (let index = 1; index < objects.length; index += 1) {
    pdf += `${String(offsets[index]).padStart(10, "0")} 00000 n \n`;
  }
  pdf += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;
  return new Blob([pdf], { type: "application/pdf" });
}

function downloadReviewPdf(root, tree) {
  const filename = `${normalized(reviewViewLabel(root)).replace(/\s+/g, "-") || "category-topic-review"}.pdf`;
  downloadBlob(buildReviewPdf(root, tree), filename);
}

export function setupStructureReview(root) {
  const tree = root.querySelector("[data-ebd-review-tree]");
  if (!tree) return;
  root.querySelector("[data-ebd-review-expand]")?.addEventListener("click", () => {
    tree.querySelectorAll("details").forEach((section) => {
      section.open = true;
    });
  });
  root.querySelector("[data-ebd-review-collapse]")?.addEventListener("click", () => {
    tree.querySelectorAll("details").forEach((section) => {
      section.open = false;
    });
  });
  root.querySelector("[data-ebd-review-pdf]")?.addEventListener("click", () => {
    downloadReviewPdf(root, tree);
  });
  root.querySelector("[data-ebd-review-csv]")?.addEventListener("click", () => {
    downloadReviewCsv(root, tree);
  });

  const topicSearch = root.querySelector("[data-ebd-review-topic-search]");
  const topicClear = root.querySelector("[data-ebd-review-topic-clear]");
  const topicStatus = root.querySelector("[data-ebd-review-topic-status]");
  const topicItems = Array.from(tree.querySelectorAll("[data-ebd-review-topic-item]"));
  const topicGroups = Array.from(tree.querySelectorAll("[data-ebd-review-topic-group]"));
  const filterTopics = () => {
    const query = normalized(topicSearch?.value || "");
    let visible = 0;
    topicItems.forEach((item) => {
      const matches = !query || normalized(item.dataset.ebdReviewSearch || "").includes(query);
      item.hidden = !matches;
      if (matches) visible += 1;
    });
    topicGroups.forEach((group) => {
      group.hidden = !Array.from(group.querySelectorAll("[data-ebd-review-topic-item]")).some((item) => !item.hidden);
    });
    if (topicClear) topicClear.disabled = !query;
    if (topicStatus) topicStatus.textContent = `${visible.toLocaleString()} ${visible === 1 ? "topic" : "topics"} shown`;
  };
  topicSearch?.addEventListener("input", filterTopics);
  topicClear?.addEventListener("click", () => {
    topicSearch.value = "";
    filterTopics();
    topicSearch.focus();
  });
}
