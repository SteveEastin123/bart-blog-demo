import { config, strings, normalized, postWord } from "./shared.js";
import { MAX_TERMS, values, termEntries, termMode, suggestionMode, modeLabel, modeRank, activeCategory, searchInput, requestUrl, updateBrowserUrl } from "./search-state.js";
import { showTooltip, hideTooltip } from "./descriptions.js";
import { renderResults } from "./results.js";
const SEARCH_CONTROLS_KEY = "ehrmanDiscovery.searchControls";

function searchControlsPreference() {
  try {
    return window.sessionStorage.getItem(SEARCH_CONTROLS_KEY) || "";
  } catch (_error) {
    return "";
  }
}

function rememberSearchControls(value) {
  try {
    window.sessionStorage.setItem(SEARCH_CONTROLS_KEY, value);
  } catch (_error) {
    // The control remains usable when browser storage is unavailable.
  }
}

function searchHasState(form) {
  return values(form, searchInput(form)).length > 0
    || Boolean(activeCategory(form))
    || Boolean(form.dataset.topic);
}

function searchCategorySummary(form) {
  const fixed = form.querySelector(".ebd-fixed-scope");
  if (fixed) return String(fixed.textContent || "").trim().replace(/\s+/g, " ");
  const category = activeCategory(form);
  const name = form.querySelector("[data-ebd-category-name]");
  if (!category || !name) return "";
  return `Category: ${String(name.textContent || "").trim()}`;
}

function searchSortSummary(form) {
  const selected = form.querySelector('input[name="ebd_sort"]:checked');
  return String(selected?.closest("label")?.textContent || "Best match").trim().replace(/\s+/g, " ");
}

function updateSearchControlsSummary(form) {
  const summary = form.querySelector("[data-ebd-search-summary]");
  const collapse = form.querySelector("[data-ebd-search-collapse]");
  const parts = [];
  const category = searchCategorySummary(form);
  const terms = values(form, searchInput(form));
  if (category) parts.push(category);
  if (terms.length) parts.push(terms.join(" + "));
  parts.push(searchSortSummary(form));
  if (summary) summary.textContent = parts.join(" | ");
  if (collapse) collapse.hidden = !searchHasState(form);
}

function setSearchControlsCollapsed(form, collapsed, remember = true) {
  const controls = form.querySelector("[data-ebd-search-expanded]");
  const compact = form.querySelector("[data-ebd-search-compact]");
  const edit = form.querySelector("[data-ebd-search-edit]");
  const resolved = Boolean(collapsed && searchHasState(form));
  if (!controls || !compact) return;
  controls.hidden = resolved;
  compact.hidden = !resolved;
  form.classList.toggle("is-collapsed", resolved);
  edit?.setAttribute("aria-expanded", String(!resolved));
  if (resolved) closeSuggestions(searchInput(form));
  if (remember) rememberSearchControls(resolved ? "collapsed" : "expanded");
}

function setupSearchControls(form) {
  const hasState = searchHasState(form);
  const preference = searchControlsPreference();
  const initialCollapse = form.dataset.ebdInitialCollapse === "true";
  updateSearchControlsSummary(form);
  setSearchControlsCollapsed(
    form,
    hasState && preference !== "expanded" && (preference === "collapsed" || initialCollapse),
    false
  );
  form.querySelector("[data-ebd-search-collapse]")?.addEventListener("click", () => {
    updateSearchControlsSummary(form);
    setSearchControlsCollapsed(form, true);
    form.querySelector("[data-ebd-search-edit]")?.focus({ preventScroll: true });
  });
  form.querySelector("[data-ebd-search-edit]")?.addEventListener("click", () => {
    setSearchControlsCollapsed(form, false);
    const target = searchInput(form)
      || form.querySelector("[data-ebd-category-toggle]")
      || form.querySelector('input[name="ebd_sort"]');
    target?.focus({ preventScroll: true });
  });
}

function suggestionList(input) {
  return input?.parentElement?.querySelector(".ebd-suggestions") || null;
}

export function closeSuggestions(input) {
  const list = suggestionList(input);
  if (!list) return;
  list.hidden = true;
  list.innerHTML = "";
  input.setAttribute("aria-expanded", "false");
  input.removeAttribute("aria-activedescendant");
  input.ebdSuggestions = [];
  input.ebdSuggestionIndex = -1;
  hideTooltip();
}

function suggestionTooltipPlacement(input) {
  if (window.innerWidth <= 720) return "auto";
  const grid = input.closest(".ebd-keyword-grid");
  if (!grid) return "auto";
  const inputRect = input.getBoundingClientRect();
  const gridRect = grid.getBoundingClientRect();
  const inputCenter = inputRect.left + (inputRect.width / 2);
  const gridCenter = gridRect.left + (gridRect.width / 2);
  return inputCenter <= gridCenter ? "right" : "left";
}

function orderedSuggestions(input, suggestions) {
  const form = input.closest("[data-ebd-search-form]");
  const mode = form?.querySelector('input[name="ebd_suggestion_order"]:checked')?.value || "popular";
  return [...suggestions].sort((left, right) => {
    if (mode === "topics-first" || mode === "keywords-first") {
      const typeDifference = modeRank(suggestionMode(right), mode) - modeRank(suggestionMode(left), mode);
      if (typeDifference) return typeDifference;
    }
    const countDifference = Number(right.postCount || 0) - Number(left.postCount || 0);
    if (countDifference) return countDifference;
    const typeDifference = modeRank(suggestionMode(right)) - modeRank(suggestionMode(left));
    if (typeDifference) return typeDifference;
    return String(left.label || "").localeCompare(String(right.label || ""));
  });
}

async function loadSuggestions(input) {
  const form = input.closest("[data-ebd-search-form]");
  const list = suggestionList(input);
  if (!form || !list || !config.suggestionsUrl) return [];
  const query = input.value.trim();
  const selected = values(form, input);
  const category = activeCategory(form);
  const topic = form.dataset.topic || "";
  if (!query && selected.length === 0 && !category && !topic) {
    closeSuggestions(input);
    return [];
  }

  if (input.ebdAbortController) input.ebdAbortController.abort();
  const controller = new AbortController();
  input.ebdAbortController = controller;
  const url = new URL(config.suggestionsUrl, window.location.origin);
  url.searchParams.set("q", query);
  termEntries(form, input).forEach((entry) => {
    url.searchParams.append("selected[]", entry.value);
    url.searchParams.append("selectedMode[]", entry.mode);
  });
  if (category) url.searchParams.set("category", category);
  if (topic) url.searchParams.set("topic", topic);

  try {
    const response = await fetch(url, { signal: controller.signal, headers: { Accept: "application/json" } });
    if (!response.ok) throw new Error("Suggestion request failed");
    const suggestions = await response.json();
    if (input.ebdAbortController !== controller) return [];
    const ordered = orderedSuggestions(input, Array.isArray(suggestions) ? suggestions : []);
    renderSuggestions(input, ordered);
    return ordered;
  } catch (error) {
    if (error.name !== "AbortError") closeSuggestions(input);
    return [];
  }
}

function renderSuggestions(input, suggestions) {
  const list = suggestionList(input);
  if (!list) return;
  list.innerHTML = "";
  input.ebdSuggestions = suggestions;
  input.ebdSuggestionIndex = -1;
  if (!suggestions.length) {
    closeSuggestions(input);
    return;
  }
  suggestions.forEach((suggestion, index) => {
    const item = document.createElement("li");
    const button = document.createElement("button");
    const main = document.createElement("span");
    const label = document.createElement("span");
    const badge = document.createElement("span");
    const count = document.createElement("span");
    item.setAttribute("role", "presentation");
    button.type = "button";
    button.className = "ebd-suggestion";
    button.id = `${list.id}-option-${index}`;
    button.setAttribute("role", "option");
    button.setAttribute("aria-selected", "false");
    button.dataset.index = String(index);
    main.className = "ebd-suggestion-main";
    label.className = "ebd-suggestion-label";
    label.textContent = suggestion.label;
    const mode = suggestionMode(suggestion);
    badge.className = `ebd-suggestion-badge is-${mode}`;
    badge.textContent = modeLabel(mode);
    count.className = "ebd-suggestion-count";
    count.textContent = `${suggestion.postCount} ${postWord(suggestion.postCount)}`;
    main.append(label, badge);
    button.append(main, count);
    button.addEventListener("mousedown", (event) => event.preventDefault());
    button.addEventListener("click", () => selectSuggestion(input, suggestion));
    if (mode === "topic" && suggestion.description) {
      button.addEventListener("mouseenter", () => showTooltip(
        button,
        suggestion.description,
        suggestionTooltipPlacement(input),
        list
      ));
      button.addEventListener("mouseleave", hideTooltip);
      button.addEventListener("focus", () => showTooltip(
        button,
        suggestion.description,
        suggestionTooltipPlacement(input),
        list
      ));
      button.addEventListener("blur", hideTooltip);
    }
    item.append(button);
    list.append(item);
  });
  list.hidden = false;
  input.setAttribute("aria-expanded", "true");
}

function setActiveSuggestion(input, index) {
  const list = suggestionList(input);
  const suggestions = input.ebdSuggestions || [];
  if (!list || list.hidden || !suggestions.length) return;
  const buttons = Array.from(list.querySelectorAll("button[data-index]"));
  const next = (index + suggestions.length) % suggestions.length;
  input.ebdSuggestionIndex = next;
  buttons.forEach((button, buttonIndex) => {
    const active = buttonIndex === next;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-selected", String(active));
  });
  input.setAttribute("aria-activedescendant", buttons[next]?.id || "");
  buttons[next]?.scrollIntoView({ block: "nearest" });
}

function selectSuggestion(input, suggestion) {
  const form = input.closest("[data-ebd-search-form]");
  if (!form || !suggestion) return;
  if (addChip(form, suggestion.label, suggestionMode(suggestion))) {
    closeSuggestions(input);
    refreshSearch(form);
    searchInput(form)?.focus({ preventScroll: true });
  }
}

function addChip(form, value, mode = "keyword") {
  const input = searchInput(form);
  const clean = value.trim();
  const current = values(form, input);
  if (!clean || current.length >= MAX_TERMS || current.some((item) => normalized(item) === normalized(clean))) {
    if (input) input.value = "";
    return false;
  }
  const chip = document.createElement("span");
  chip.className = "ebd-keyword-slot ebd-keyword-chip";
  const hidden = document.createElement("input");
  hidden.type = "hidden";
  hidden.name = "ebd_keyword[]";
  hidden.value = clean;
  const hiddenMode = document.createElement("input");
  hiddenMode.type = "hidden";
  hiddenMode.name = "ebd_term_mode[]";
  hiddenMode.value = termMode(mode);
  const content = document.createElement("span");
  content.className = "ebd-keyword-chip-content";
  const label = document.createElement("span");
  label.className = "ebd-keyword-chip-label";
  label.textContent = clean;
  const badge = document.createElement("span");
  const typeLabel = modeLabel(termMode(mode));
  badge.className = `ebd-selected-term-badge is-${termMode(mode)}`;
  badge.textContent = typeLabel;
  badge.setAttribute("aria-label", `Term type: ${typeLabel}`);
  content.append(label, badge);
  const remove = document.createElement("button");
  remove.type = "button";
  remove.className = "ebd-keyword-remove";
  remove.dataset.ebdRemove = "";
  remove.setAttribute("aria-label", `Remove ${clean}`);
  remove.innerHTML = "&times;";
  chip.append(hidden, hiddenMode, content, remove);
  const wrap = form.querySelector(".ebd-keyword-input-wrap");
  form.querySelector("[data-ebd-chip-list]")?.insertBefore(chip, wrap);
  if (input) input.value = "";
  updateSlots(form);
  return true;
}

function updateSlots(form) {
  const list = form.querySelector("[data-ebd-chip-list]");
  const input = searchInput(form);
  const wrap = form.querySelector(".ebd-keyword-input-wrap");
  if (!list || !input || !wrap) return;
  list.querySelectorAll(".ebd-keyword-empty").forEach((item) => item.remove());
  const count = values(form, input).length;
  wrap.hidden = count >= MAX_TERMS;
  input.disabled = count >= MAX_TERMS;
  input.placeholder = `Keyword ${Math.min(count + 1, MAX_TERMS)}`;
  input.setAttribute("aria-label", input.placeholder);
  const active = count < MAX_TERMS ? 1 : 0;
  for (let index = count + active + 1; index <= MAX_TERMS; index += 1) {
    const empty = document.createElement("span");
    empty.className = "ebd-keyword-slot ebd-keyword-empty";
    empty.textContent = `Keyword ${index}`;
    list.append(empty);
  }
  const clear = form.querySelector("[data-ebd-clear]");
  const editableCategory = form.querySelector("[data-ebd-category]");
  if (clear) clear.disabled = count === 0 && (!editableCategory || !editableCategory.value);
  updateSearchControlsSummary(form);
}

async function refreshSearch(form, page = 1) {
  const results = form.parentElement?.querySelector("[data-ebd-results]")
    || form.closest(".ebd-discovery")?.querySelector("[data-ebd-results]");
  if (!results || !config.searchUrl) return;
  if (form.ebdSearchController) form.ebdSearchController.abort();
  const controller = new AbortController();
  form.ebdSearchController = controller;
  form.classList.add("is-loading");
  results.setAttribute("aria-busy", "true");
  try {
    const response = await fetch(requestUrl(form, page), { signal: controller.signal, headers: { Accept: "application/json" } });
    if (!response.ok) throw new Error("Search request failed");
    const result = await response.json();
    if (form.ebdSearchController !== controller) return;
    renderResults(results, result, form);
    updateSearchControlsSummary(form);
    const headingCount = form.closest(".ebd-discovery")?.querySelector("[data-ebd-result-count]");
    if (headingCount) {
      const count = Number(result.count || 0);
      headingCount.textContent = `${count} ${postWord(count)}`;
    }
    updateBrowserUrl(form, Number(result.page || 1));
  } catch (error) {
    if (error.name !== "AbortError") {
      results.textContent = strings.requestFailed || "The search could not be completed. Please try again.";
    }
  } finally {
    if (form.ebdSearchController === controller) {
      form.classList.remove("is-loading");
      results.removeAttribute("aria-busy");
    }
  }
}

function setupCategory(form) {
  const box = form.querySelector("[data-ebd-category-combobox]");
  if (!box) return;
  const hidden = box.querySelector("[data-ebd-category]");
  const toggle = box.querySelector("[data-ebd-category-toggle]");
  const options = box.querySelector("[data-ebd-category-options]");
  const name = box.querySelector("[data-ebd-category-name]");
  const count = box.querySelector("[data-ebd-category-count]");
  if (!hidden || !toggle || !options) return;
  const close = () => {
    options.hidden = true;
    toggle.setAttribute("aria-expanded", "false");
  };
  toggle.addEventListener("click", () => {
    options.hidden = !options.hidden;
    toggle.setAttribute("aria-expanded", String(!options.hidden));
  });
  options.addEventListener("click", (event) => {
    const option = event.target.closest("[data-ebd-category-option]");
    if (!option) return;
    hidden.value = option.value || "";
    form.dataset.category = hidden.value;
    options.querySelectorAll("[data-ebd-category-option]").forEach((item) => {
      item.setAttribute("aria-selected", String(item === option));
    });
    if (name) name.textContent = option.dataset.label || "All categories";
    if (count) count.textContent = option.dataset.count || "";
    close();
    closeSuggestions(searchInput(form));
    updateSlots(form);
    refreshSearch(form);
  });
  toggle.addEventListener("keydown", (event) => {
    if (event.key === "Escape") close();
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      options.hidden = false;
      toggle.setAttribute("aria-expanded", "true");
      const items = Array.from(options.querySelectorAll("[data-ebd-category-option]"));
      (event.key === "ArrowDown" ? items[0] : items[items.length - 1])?.focus();
    }
  });
  options.addEventListener("keydown", (event) => {
    const option = event.target.closest("[data-ebd-category-option]");
    if (!option) return;
    const items = Array.from(options.querySelectorAll("[data-ebd-category-option]"));
    const index = items.indexOf(option);
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      const move = event.key === "ArrowDown" ? 1 : -1;
      items[(index + move + items.length) % items.length]?.focus();
    } else if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      option.click();
    } else if (event.key === "Escape") {
      close();
      toggle.focus();
    }
  });
  document.addEventListener("click", (event) => {
    if (!box.contains(event.target)) close();
  });
}

export function setupForm(form) {
  const input = searchInput(form);
  setupCategory(form);
  updateSlots(form);
  setupSearchControls(form);
  form.addEventListener("submit", (event) => event.preventDefault());
  form.addEventListener("click", (event) => {
    const remove = event.target.closest("[data-ebd-remove]");
    if (remove) {
      remove.closest(".ebd-keyword-chip")?.remove();
      updateSlots(form);
      refreshSearch(form);
      searchInput(form)?.focus({ preventScroll: true });
      return;
    }
    if (event.target.closest("[data-ebd-clear]")) {
      form.querySelectorAll(".ebd-keyword-chip").forEach((chip) => chip.remove());
      const category = form.querySelector("[data-ebd-category]");
      if (category) {
        category.value = "";
        form.dataset.category = "";
        form.querySelectorAll("[data-ebd-category-option]").forEach((option) => {
          option.setAttribute("aria-selected", String(option.value === ""));
        });
        const categoryName = form.querySelector("[data-ebd-category-name]");
        const categoryCount = form.querySelector("[data-ebd-category-count]");
        if (categoryName) categoryName.textContent = "All categories";
        if (categoryCount) categoryCount.textContent = "";
      }
      if (input) input.value = "";
      updateSlots(form);
      closeSuggestions(input);
      refreshSearch(form);
    }
  });
  form.querySelectorAll('input[name="ebd_sort"]').forEach((radio) => {
    radio.addEventListener("change", () => {
      updateSearchControlsSummary(form);
      refreshSearch(form);
    });
  });
  form.querySelectorAll('input[name="ebd_suggestion_order"]').forEach((radio) => {
    radio.addEventListener("change", () => {
      const currentInput = searchInput(form);
      const current = currentInput?.ebdSuggestions || [];
      if (currentInput && current.length) {
        renderSuggestions(currentInput, orderedSuggestions(currentInput, current));
      }
    });
  });
  if (!input) return;
  let timer = null;
  input.addEventListener("input", () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => loadSuggestions(input), 100);
  });
  input.addEventListener("focus", () => {
    if (input.value.trim() || values(form, input).length || activeCategory(form) || form.dataset.topic) {
      loadSuggestions(input);
    }
  });
  input.addEventListener("pointerdown", () => {
    const list = suggestionList(input);
    if (document.activeElement === input && list?.hidden
      && (input.value.trim() || values(form, input).length || activeCategory(form) || form.dataset.topic)) {
      loadSuggestions(input);
    }
  });
  input.addEventListener("keydown", async (event) => {
    const suggestions = input.ebdSuggestions || [];
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (!suggestions.length) await loadSuggestions(input);
      setActiveSuggestion(input, (input.ebdSuggestionIndex || 0) + (event.key === "ArrowDown" ? 1 : -1));
    } else if (event.key === "Enter") {
      event.preventDefault();
      let current = input.ebdSuggestions || [];
      if (!current.length) current = await loadSuggestions(input);
      const active = input.ebdSuggestionIndex >= 0 ? current[input.ebdSuggestionIndex] : null;
      const exact = current
        .filter((item) => normalized(item.label) === normalized(input.value))
        .sort((left, right) => {
          const preferred = (item) => suggestionMode(item) === "topic-keyword" ? 3 : (suggestionMode(item) === "topic" ? 2 : 1);
          return preferred(right) - preferred(left);
        })[0];
      selectSuggestion(input, active || exact);
    } else if (event.key === "Escape") {
      closeSuggestions(input);
    } else if (event.key === "Tab") {
      closeSuggestions(input);
    }
  });
}
