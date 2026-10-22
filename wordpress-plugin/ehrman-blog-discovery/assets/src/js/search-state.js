import { config, strings, normalized } from "./shared.js";
export const MAX_TERMS = 4;

export function values(form, exceptInput) {
  return termEntries(form, exceptInput).map((entry) => entry.value);
}

export function termEntries(form, exceptInput) {
  const found = [];
  const seen = new Set();
  form.querySelectorAll(".ebd-keyword-chip").forEach((chip) => {
    const input = chip.querySelector('input[name="ebd_keyword[]"]');
    const modeInput = chip.querySelector('input[name="ebd_term_mode[]"]');
    if (!input) return;
    if (input === exceptInput) return;
    const value = input.value.trim();
    const key = normalized(value);
    if (key && !seen.has(key)) {
      seen.add(key);
      found.push({ value, mode: termMode(modeInput?.value) });
    }
  });
  return found.slice(0, MAX_TERMS);
}

export function termMode(value) {
  return ["topic", "topic-keyword", "keyword"].includes(value) ? value : "keyword";
}

export function suggestionMode(suggestion) {
  if (suggestion?.mode) return termMode(suggestion.mode);
  return suggestion?.isTopic ? "topic" : "keyword";
}

export function modeLabel(mode) {
  if (mode === "topic") return strings.topic || "Topic";
  return strings.keyword || "Keyword";
}

export function modeRank(mode, order = "topics-first") {
  if (order === "keywords-first") {
    return mode === "keyword" ? 3 : (mode === "topic-keyword" ? 2 : 1);
  }
  return mode === "topic" ? 3 : (mode === "topic-keyword" ? 2 : 1);
}

export function activeCategory(form) {
  const filter = form.querySelector("[data-ebd-category]");
  return filter ? filter.value.trim() : (form.dataset.category || "");
}

export function searchInput(form) {
  return form.querySelector(".ebd-keyword-input");
}

export function requestUrl(form, page = 1) {
  const url = new URL(config.searchUrl, window.location.origin);
  termEntries(form, searchInput(form)).forEach((entry) => {
    url.searchParams.append("term[]", entry.value);
    url.searchParams.append("mode[]", entry.mode);
  });
  const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
  url.searchParams.set("sort", sort);
  const category = activeCategory(form);
  if (category) url.searchParams.set("category", category);
  if (form.dataset.topic) url.searchParams.set("topic", form.dataset.topic);
  if (page > 1) url.searchParams.set("page", String(page));
  return url;
}

export function browserUrl(form, page = 1) {
  const url = new URL(form.action, window.location.origin);
  url.searchParams.delete("ebd_keyword[]");
  url.searchParams.delete("ebd_keyword");
  url.searchParams.delete("ebd_term_mode[]");
  url.searchParams.delete("ebd_term_mode");
  url.searchParams.delete("ebd_sort");
  url.searchParams.delete("ebd_page");
  termEntries(form, searchInput(form)).forEach((entry) => {
    url.searchParams.append("ebd_keyword[]", entry.value);
    url.searchParams.append("ebd_term_mode[]", entry.mode);
  });
  const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
  if (sort !== "ranked") url.searchParams.set("ebd_sort", sort);
  const categoryInput = form.querySelector("[data-ebd-category]");
  if (categoryInput) {
    url.searchParams.delete("ebd_category");
    if (categoryInput.value) url.searchParams.set("ebd_category", categoryInput.value);
  }
  const question = form.querySelector('[name="ebd_question"]')?.value || "";
  const requestId = form.querySelector('input[name="ebd_ai_request"]')?.value || "";
  if (question) url.searchParams.set("ebd_question", question);
  if (requestId) url.searchParams.set("ebd_ai_request", requestId);
  if (page > 1) {
    url.searchParams.set("ebd_page", String(page));
    url.hash = "ebd-results";
  }
  return url;
}

export function updateBrowserUrl(form, page = 1) {
  const url = browserUrl(form, page);
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
}
