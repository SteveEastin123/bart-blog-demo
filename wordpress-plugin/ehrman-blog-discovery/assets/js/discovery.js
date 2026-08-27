(function () {
  "use strict";

  const config = window.EhrmanDiscovery || {};
  const strings = config.strings || {};
  const MAX_TERMS = 4;
  const BACK_TO_TOP_THRESHOLD = 10;
  const SEARCH_CONTROLS_KEY = "ehrmanDiscovery.searchControls";
  const DESCRIPTION_MODE_KEY = "ehrmanDiscovery.descriptionMode";
  let tooltip = null;

  function normalized(value) {
    return String(value || "")
      .trim()
      .toLocaleLowerCase()
      .replace(/&/g, " and ")
      .replace(/[^a-z0-9]+/g, " ")
      .trim()
      .replace(/\s+/g, " ");
  }

  function values(form, exceptInput) {
    return termEntries(form, exceptInput).map((entry) => entry.value);
  }

  function termEntries(form, exceptInput) {
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

  function termMode(value) {
    return ["topic", "topic-keyword", "keyword"].includes(value) ? value : "keyword";
  }

  function suggestionMode(suggestion) {
    if (suggestion?.mode) return termMode(suggestion.mode);
    return suggestion?.isTopic ? "topic" : "keyword";
  }

  function modeLabel(mode) {
    if (mode === "topic") return strings.topic || "Topic";
    return strings.keyword || "Keyword";
  }

  function modeRank(mode, order = "topics-first") {
    if (order === "keywords-first") {
      return mode === "keyword" ? 3 : (mode === "topic-keyword" ? 2 : 1);
    }
    return mode === "topic" ? 3 : (mode === "topic-keyword" ? 2 : 1);
  }

  function activeCategory(form) {
    const filter = form.querySelector("[data-ebd-category]");
    return filter ? filter.value.trim() : (form.dataset.category || "");
  }

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

  function searchInput(form) {
    return form.querySelector(".ebd-keyword-input");
  }

  function suggestionList(input) {
    return input?.parentElement?.querySelector(".ebd-suggestions") || null;
  }

  function closeSuggestions(input) {
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

  function getTooltip() {
    if (!tooltip) {
      tooltip = document.createElement("div");
      tooltip.className = "ebd-tooltip";
      tooltip.setAttribute("role", "tooltip");
      tooltip.hidden = true;
      document.body.appendChild(tooltip);
    }
    return tooltip;
  }

  function showTooltip(element, text, placement = "auto", horizontalElement = element) {
    if (!text) return;
    const node = getTooltip();
    node.textContent = text;
    node.hidden = false;
    const rect = element.getBoundingClientRect();
    const horizontalRect = horizontalElement.getBoundingClientRect();
    let resolvedPlacement = placement;
    let width = Math.min(520, window.innerWidth - 32);

    if (placement === "right" || placement === "left") {
      const rightSpace = window.innerWidth - horizontalRect.right - 28;
      const leftSpace = horizontalRect.left - 28;
      let available = placement === "right" ? rightSpace : leftSpace;
      const opposite = placement === "right" ? leftSpace : rightSpace;
      if (available < 260 && opposite > available) {
        resolvedPlacement = placement === "right" ? "left" : "right";
        available = opposite;
      }
      if (available >= 260) {
        width = Math.min(520, Math.floor(available));
      } else {
        resolvedPlacement = "auto";
      }
    }

    node.style.width = `${width}px`;
    const measured = node.getBoundingClientRect();
    let left;
    let top;
    if (resolvedPlacement === "right" || resolvedPlacement === "left") {
      left = resolvedPlacement === "right"
        ? horizontalRect.right + 12
        : horizontalRect.left - measured.width - 12;
      top = Math.max(16, Math.min(
        rect.top + ((rect.height - measured.height) / 2),
        window.innerHeight - measured.height - 16
      ));
    } else {
      left = Math.max(16, Math.min(rect.left, window.innerWidth - measured.width - 16));
      top = rect.bottom + 8;
      if (top + measured.height > window.innerHeight - 16) {
        top = Math.max(16, rect.top - measured.height - 8);
      }
    }
    node.style.left = `${left}px`;
    node.style.top = `${top}px`;
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

  function hideTooltip() {
    if (!tooltip) return;
    tooltip.hidden = true;
    tooltip.textContent = "";
  }

  function postWord(count) {
    return count === 1 ? (strings.post || "post") : (strings.posts || "posts");
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

  function requestUrl(form, page = 1) {
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

  function browserUrl(form, page = 1) {
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

  function updateBrowserUrl(form, page = 1) {
    const url = browserUrl(form, page);
    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }

  async function refreshSearch(form, page = 1, options = {}) {
    const results = form.parentElement?.querySelector("[data-ebd-results]")
      || form.closest(".ebd-discovery")?.querySelector("[data-ebd-results]");
    if (!results || !config.searchUrl) return;
    if (!options.preserveRefined) {
      form.ebdRefineController?.abort();
      form.ebdRefineController = null;
      form.ebdRefinedResult = null;
      form.ebdBroaderResult = null;
      form.ebdShowingBroader = false;
    }
    if (options.notice) form.ebdResultsNotice = options.notice;
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
      if (options.preserveRefined) {
        form.ebdBroaderResult = result;
        form.ebdShowingBroader = true;
      }
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

  function resultSummaryLabel(result) {
    const count = Number(result.count || 0);
    const page = Math.max(1, Number(result.page || 1));
    const perPage = Math.max(0, Number(result.per_page || 0));
    const totalPages = Math.max(0, Number(result.total_pages || 0));
    const visible = Array.isArray(result.posts) ? result.posts.length : 0;
    if (totalPages <= 1 || perPage <= 0) return `${count} ${postWord(count)}`;
    const start = ((page - 1) * perPage) + 1;
    const end = Math.min(count, start + visible - 1);
    return `${strings.showing || "Showing"} ${start}-${end} ${strings.of || "of"} ${count} ${strings.posts || "posts"}`;
  }

  function paginationPages(current, total) {
    const pages = [...new Set([1, current - 1, current, current + 1, total])]
      .filter((page) => page >= 1 && page <= total)
      .sort((left, right) => left - right);
    const entries = [];
    let previous = 0;
    pages.forEach((page) => {
      if (previous && page > previous + 1) entries.push(null);
      entries.push(page);
      previous = page;
    });
    return entries;
  }

  function paginationLink(form, page, label, className = "") {
    const link = document.createElement("a");
    link.className = `ebd-pagination-link ${className}`.trim();
    link.href = browserUrl(form, page).href;
    link.textContent = label;
    return link;
  }

  function renderPagination(container, result, form) {
    const current = Math.max(1, Number(result.page || 1));
    const total = Math.max(0, Number(result.total_pages || 0));
    if (!form || total <= 1) return;

    const nav = document.createElement("nav");
    nav.className = "ebd-pagination";
    nav.setAttribute("aria-label", "Search results pages");
    if (current > 1) {
      nav.append(paginationLink(form, current - 1, strings.previous || "Previous", "ebd-pagination-previous"));
    } else {
      const previous = document.createElement("span");
      previous.className = "ebd-pagination-link ebd-pagination-previous is-disabled";
      previous.setAttribute("aria-disabled", "true");
      previous.textContent = strings.previous || "Previous";
      nav.append(previous);
    }

    const pages = document.createElement("span");
    pages.className = "ebd-pagination-pages";
    paginationPages(current, total).forEach((page) => {
      if (page === null) {
        const ellipsis = document.createElement("span");
        ellipsis.className = "ebd-pagination-ellipsis";
        ellipsis.setAttribute("aria-hidden", "true");
        ellipsis.textContent = "...";
        pages.append(ellipsis);
      } else if (page === current) {
        const selected = document.createElement("span");
        selected.className = "ebd-pagination-link is-current";
        selected.setAttribute("aria-current", "page");
        selected.textContent = String(page);
        pages.append(selected);
      } else {
        const link = paginationLink(form, page, String(page));
        link.setAttribute("aria-label", `${strings.page || "Page"} ${page}`);
        pages.append(link);
      }
    });
    nav.append(pages);

    const status = document.createElement("span");
    status.className = "ebd-pagination-status";
    status.textContent = `${strings.page || "Page"} ${current} ${strings.of || "of"} ${total}`;
    nav.append(status);

    if (current < total) {
      nav.append(paginationLink(form, current + 1, strings.next || "Next", "ebd-pagination-next"));
    } else {
      const next = document.createElement("span");
      next.className = "ebd-pagination-link ebd-pagination-next is-disabled";
      next.setAttribute("aria-disabled", "true");
      next.textContent = strings.next || "Next";
      nav.append(next);
    }
    if (form.matches("[data-ebd-semantic-form]")) {
      nav.addEventListener("click", (event) => {
        const link = event.target.closest(".ebd-pagination-link[href]");
        if (!link || !form.ebdBroaderResult || !form.ebdShowingBroader) return;
        event.preventDefault();
        event.stopPropagation();
        const page = Number(new URL(link.href).searchParams.get("ebd_page") || 1);
        const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
        form.ebdBroaderResult = semanticPageResult(form.ebdBroaderResult, page, sort);
        renderResults(container, form.ebdBroaderResult, form);
        updateBrowserUrl(form, page);
        container.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }
    container.append(nav);
  }

  function renderResults(container, result, form) {
    container.innerHTML = "";
    const count = Number(result.count || 0);
    const terms = Array.isArray(result.terms) ? result.terms : [];
    const context = container.dataset.context || "";
    const summary = document.createElement("p");
    summary.className = "ebd-results-summary";
    summary.setAttribute("aria-live", "polite");
    const countStrong = document.createElement("strong");
    if (result.refined) {
      const sourceLabel = result.semantic ? "semantic candidates" : "matching posts";
      countStrong.textContent = `${count} ${count === 1 ? "post" : "posts"} selected from ${Number(result.original_count || 0)} ${sourceLabel}`;
    } else {
      countStrong.textContent = resultSummaryLabel(result);
    }
    summary.append(countStrong);
    const contextIsTerm = terms.some((term) => normalized(term) === normalized(context));
    if (!result.refined && context && !contextIsTerm) {
      summary.append(document.createTextNode(" in "));
      const contextStrong = document.createElement("strong");
      contextStrong.textContent = context;
      summary.append(contextStrong);
    }
    if (!result.refined && terms.length) {
      summary.append(document.createTextNode(` ${count === 1 ? "matches" : "match"} `));
      const termStrong = document.createElement("strong");
      termStrong.textContent = terms.join(" + ");
      summary.append(termStrong);
    }
    summary.append(document.createTextNode("."));
    container.append(summary);
    if (form.ebdResultsNotice) {
      const notice = document.createElement("p");
      notice.className = "ebd-results-guidance ebd-ai-results-notice";
      notice.textContent = form.ebdResultsNotice;
      container.append(notice);
      form.ebdResultsNotice = "";
    }
    const guidance = document.createElement("p");
    guidance.className = "ebd-results-guidance";
    if (count > 100) {
      guidance.textContent = strings.manyResults || "Many posts match. Add another topic or keyword to narrow the results.";
    } else if (count === 0 && terms.length > 0) {
      guidance.textContent = strings.zeroResults || "No posts match all the selected terms. Remove a term or try a different search.";
    } else if (count <= 3 && terms.length > 1) {
      guidance.textContent = strings.fewResults || "Only a few posts match all the selected terms. Remove a term to broaden the results.";
    }
    if (guidance.textContent) container.append(guidance);
    const question = form.querySelector('[name="ebd_question"]')?.value || "";
    const requestId = form.querySelector('input[name="ebd_ai_request"]')?.value || "";
    if (question && requestId && (terms.length || result.semantic)) {
      if (result.refined && Number(result.original_count || 0) > count) {
        container.append(resultViewControl("broader"));
      } else if (!result.refined && form.ebdRefinedResult) {
        container.append(resultViewControl("refined"));
      }
      container.append(feedbackControl(requestId));
    }
    if (!Array.isArray(result.posts) || !result.posts.length) {
      const empty = document.createElement("p");
      empty.className = "ebd-empty";
      empty.textContent = strings.noResults || "No posts matched this request.";
      container.append(empty);
      return;
    }
    const list = document.createElement("ul");
    list.className = "ebd-post-list";
    result.posts.forEach((post) => {
      const item = document.createElement("li");
      const title = document.createElement("a");
      const meta = document.createElement("p");
      const description = document.createElement("p");
      item.className = "ebd-post-item";
      title.className = "ebd-post-title";
      title.href = post.url;
      title.textContent = post.title;
      title.dataset.description = post.description || "";
      meta.className = "ebd-post-meta";
      const author = post.author || strings.unknownAuthor || "unknown author";
      meta.textContent = [`By ${author}`, post.date_text, context].filter(Boolean).join(" | ");
      description.className = "ebd-post-description";
      description.textContent = post.description || "";
      item.append(title, meta, description);
      list.append(item);
    });
    container.append(list);
    if (result.posts.length >= BACK_TO_TOP_THRESHOLD) {
      const wrapper = document.createElement("p");
      const button = document.createElement("button");
      const arrow = document.createElement("span");
      wrapper.className = "ebd-back-to-top";
      button.type = "button";
      button.dataset.ebdBackToTop = "true";
      arrow.setAttribute("aria-hidden", "true");
      arrow.textContent = "\u2191";
      button.append(arrow, document.createTextNode(" Back to top"));
      wrapper.append(button);
      container.append(wrapper);
    }
    renderPagination(container, result, form);
    applyDescriptionMode(container.closest(".ebd-discovery") || document);
    setupTitleTooltips(container);
  }

  function resultViewControl(target) {
    const wrapper = document.createElement("div");
    const button = document.createElement("button");
    const status = document.createElement("span");
    wrapper.className = "ebd-ai-refine";
    wrapper.dataset.ebdAiRefine = "true";
    button.type = "button";
    if (target === "broader") {
      button.dataset.ebdShowBroader = "true";
      button.textContent = strings.showBroader || "Show broader results";
    } else {
      button.dataset.ebdShowRefined = "true";
      button.textContent = strings.showRefined || "Show refined results";
    }
    status.dataset.ebdRefineStatus = "true";
    status.setAttribute("aria-live", "polite");
    wrapper.append(button, status);
    return wrapper;
  }

  function sortedRefinedResult(result, sort) {
    if (!result || sort === "ranked") return result;
    const posts = [...(result.posts || [])].sort((left, right) => {
      const comparison = String(left.published_at || "").localeCompare(String(right.published_at || ""));
      return sort === "oldest" ? comparison : -comparison;
    });
    return { ...result, posts, sort };
  }

  function semanticPageResult(result, page = 1, sort = "ranked") {
    if (!result) return result;
    const rankedPosts = Array.isArray(result.ranked_posts)
      ? result.ranked_posts
      : [...(result.posts || [])];
    const ordered = [...rankedPosts];
    if (sort !== "ranked") {
      ordered.sort((left, right) => {
        const comparison = String(left.published_at || "").localeCompare(String(right.published_at || ""));
        return sort === "oldest" ? comparison : -comparison;
      });
    }
    const perPage = Math.max(1, Number(result.per_page || 25));
    const count = rankedPosts.length;
    const totalPages = count ? Math.ceil(count / perPage) : 0;
    const current = totalPages ? Math.min(Math.max(1, page), totalPages) : 1;
    return {
      ...result,
      ranked_posts: rankedPosts,
      posts: ordered.slice((current - 1) * perPage, current * perPage),
      count,
      page: current,
      per_page: perPage,
      total_pages: totalPages,
      sort,
      semantic: true,
    };
  }

  async function refineSearch(form, results) {
    if (!form || !results || !config.refineUrl) {
      if (form && results) {
        await refreshSearch(form, 1, { notice: strings.refineFallback || "AI refinement was unavailable, so the broader matches are shown." });
      }
      return;
    }
    const entries = termEntries(form, searchInput(form));
    const question = form.querySelector('input[name="ebd_question"]')?.value || "";
    const requestId = form.querySelector('input[name="ebd_ai_request"]')?.value || "";
    form.ebdRefineController?.abort();
    const controller = new AbortController();
    form.ebdRefineController = controller;
    results.setAttribute("aria-busy", "true");
    try {
      const response = await fetch(config.refineUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          question,
          request_id: requestId,
          term: entries.map((entry) => entry.value),
          mode: entries.map((entry) => entry.mode),
        }),
        signal: controller.signal,
      });
      const payload = await response.json();
      if (form.ebdRefineController !== controller) return;
      if (!response.ok) throw new Error(payload?.message || strings.refineFailed || "The results could not be refined.");
      form.ebdBroaderResult = payload.broader || null;
      if (!Array.isArray(payload.posts) || !payload.posts.length) {
        form.ebdRefinedResult = null;
        form.ebdShowingBroader = true;
        if (form.ebdBroaderResult) {
          form.ebdResultsNotice = strings.noRefinedPosts || "AI did not select a narrower set, so the broader matches are shown.";
          renderResults(results, form.ebdBroaderResult, form);
        } else {
          await refreshSearch(form, 1, { notice: strings.noRefinedPosts || "AI did not select a narrower set, so the broader matches are shown." });
        }
        return;
      }
      form.ebdRefinedResult = payload;
      form.ebdShowingBroader = false;
      renderResults(results, payload, form);
    } catch (error) {
      if (error.name === "AbortError") return;
      form.ebdRefinedResult = null;
      form.ebdShowingBroader = true;
      const notice = strings.refineFallback || "AI refinement was unavailable, so the broader matches are shown.";
      if (form.ebdBroaderResult) {
        form.ebdResultsNotice = notice;
        renderResults(results, form.ebdBroaderResult, form);
      } else {
        await refreshSearch(form, 1, { notice });
      }
    } finally {
      if (form.ebdRefineController === controller) {
        form.ebdRefineController = null;
        results.removeAttribute("aria-busy");
      }
    }
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-ebd-show-broader],[data-ebd-show-refined]");
    if (!button) return;
    const discovery = button.closest(".ebd-discovery");
    const form = discovery?.querySelector("[data-ebd-search-form],[data-ebd-semantic-form]");
    const results = discovery?.querySelector("[data-ebd-results]");
    if (!form || !results) return;

    button.disabled = true;
    if (button.matches("[data-ebd-show-refined]") && form.ebdRefinedResult) {
      form.ebdShowingBroader = false;
      const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
      renderResults(results, sortedRefinedResult(form.ebdRefinedResult, sort), form);
      updateBrowserUrl(form);
      return;
    }
    form.ebdShowingBroader = true;
    const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
    if (form.matches("[data-ebd-semantic-form]") && form.ebdBroaderResult) {
      form.ebdBroaderResult = semanticPageResult(form.ebdBroaderResult, 1, sort);
      renderResults(results, form.ebdBroaderResult, form);
      updateBrowserUrl(form);
      return;
    }
    if (form.ebdBroaderResult && form.ebdBroaderResult.sort === sort) {
      renderResults(results, form.ebdBroaderResult, form);
      updateBrowserUrl(form, Number(form.ebdBroaderResult.page || 1));
      return;
    }
    await refreshSearch(form, 1, { preserveRefined: true });
  });

  function feedbackControl(requestId) {
    const section = document.createElement("section");
    const prompt = document.createElement("span");
    const status = document.createElement("span");
    section.className = "ebd-ai-feedback";
    section.dataset.ebdAiFeedback = "true";
    section.dataset.requestId = requestId;
    prompt.textContent = "Were these search results helpful?";
    section.append(prompt);
    ["Yes", "No"].forEach((label) => {
      const button = document.createElement("button");
      button.type = "button";
      button.dataset.ebdFeedbackValue = label.toLowerCase();
      button.textContent = label;
      section.append(button);
    });
    status.className = "ebd-ai-feedback-status";
    status.dataset.ebdFeedbackStatus = "true";
    status.setAttribute("aria-live", "polite");
    section.append(status);
    return section;
  }

  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-ebd-feedback-value]");
    if (!button || !config.feedbackUrl) return;
    const section = button.closest("[data-ebd-ai-feedback]");
    const status = section?.querySelector("[data-ebd-feedback-status]");
    if (!section || !status || section.dataset.submitted === "true") return;
    const buttons = section.querySelectorAll("[data-ebd-feedback-value]");
    buttons.forEach((item) => { item.disabled = true; });
    try {
      const response = await fetch(config.feedbackUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          request_id: section.dataset.requestId || "",
          helpful: button.dataset.ebdFeedbackValue === "yes",
        }),
      });
      if (!response.ok) throw new Error("Feedback request failed");
      section.dataset.submitted = "true";
      button.classList.add("is-selected");
      status.textContent = strings.feedbackThanks || "Thank you. Your feedback will help improve the search.";
    } catch (error) {
      buttons.forEach((item) => { item.disabled = false; });
      status.textContent = strings.feedbackFailed || "The feedback could not be saved. Please try again.";
    }
  });

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

  function setupForm(form) {
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
        if (form.ebdRefinedResult && !form.ebdShowingBroader) {
          const results = form.closest(".ebd-discovery")?.querySelector("[data-ebd-results]");
          if (results) {
            renderResults(results, sortedRefinedResult(form.ebdRefinedResult, radio.value), form);
            updateBrowserUrl(form);
          }
          return;
        }
        refreshSearch(form, 1, { preserveRefined: Boolean(form.ebdRefinedResult) });
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

  function setupTitleTooltips(root) {
    root.querySelectorAll(".ebd-item-title,.ebd-post-title").forEach((title) => {
      if (title.dataset.ebdTooltipReady) return;
      title.dataset.ebdTooltipReady = "true";
      const isNavigation = title.classList.contains("ebd-navigation-link");
      const anchor = isNavigation
        ? (title.querySelector(".ebd-navigation-name > span:last-child") || title)
        : title;
      const hoverTarget = anchor;
      hoverTarget.addEventListener("mouseenter", () => {
        const pageRoot = title.closest(".ebd-discovery") || document;
        if (descriptionMode(pageRoot) === "hover") {
          showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
        }
      });
      hoverTarget.addEventListener("mouseleave", hideTooltip);
      title.addEventListener("focus", () => {
        const pageRoot = title.closest(".ebd-discovery") || document;
        if (descriptionMode(pageRoot) === "hover") {
          showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
        }
      });
      title.addEventListener("blur", hideTooltip);
    });
    root.querySelectorAll(".ebd-review-name").forEach((name) => {
      if (name.dataset.ebdTooltipReady) return;
      const section = name.closest(".ebd-review-area,.ebd-review-category,.ebd-review-topic");
      const description = directChild(section, ".ebd-review-description");
      if (!description?.textContent.trim()) return;
      const anchor = name.querySelector("span:last-child") || name;
      name.dataset.ebdTooltipReady = "true";
      const show = () => {
        const pageRoot = name.closest(".ebd-discovery") || document;
        if (descriptionMode(pageRoot) === "hover") {
          showTooltip(anchor, description.textContent.trim(), "right");
        }
      };
      anchor.addEventListener("mouseenter", show);
      anchor.addEventListener("mouseleave", hideTooltip);
      anchor.addEventListener("focus", show);
      anchor.addEventListener("blur", hideTooltip);
    });
  }

  function descriptionMode(root) {
    return root.querySelector("[data-ebd-description-mode] input:checked")?.value || "hidden";
  }

  function descriptionPreferenceScope(control) {
    return control.dataset.scope === "posts" ? "posts" : "browse";
  }

  function savedDescriptionMode(control) {
    try {
      return window.sessionStorage.getItem(`${DESCRIPTION_MODE_KEY}.${descriptionPreferenceScope(control)}`) || "";
    } catch (_error) {
      return "";
    }
  }

  function rememberDescriptionMode(control, mode) {
    try {
      window.sessionStorage.setItem(`${DESCRIPTION_MODE_KEY}.${descriptionPreferenceScope(control)}`, mode);
    } catch (_error) {
      // The display control remains usable when browser storage is unavailable.
    }
  }

  function applyDescriptionMode(root) {
    const mode = descriptionMode(root);
    root.querySelectorAll(".ebd-item-description,.ebd-post-description,.ebd-review-description").forEach((description) => {
      description.hidden = mode !== "always";
    });
    if (mode !== "hover") hideTooltip();
  }

  function setupDescriptionMode(control) {
    const root = control.closest(".ebd-discovery") || document;
    const preferred = savedDescriptionMode(control);
    let mode = ["always", "hover", "hidden"].includes(preferred)
      ? preferred
      : (control.dataset.defaultMode || "hover");
    if (mode === "hover" && window.matchMedia("(hover: none) and (pointer: coarse)").matches) {
      mode = "hidden";
    }
    const selected = control.querySelector(`input[value="${mode}"]`);
    if (selected) selected.checked = true;
    control.addEventListener("change", (event) => {
      if (!event.target.matches('input[type="radio"]')) return;
      rememberDescriptionMode(control, event.target.value);
      applyDescriptionMode(root);
    });
    applyDescriptionMode(root);
  }

  function compactText(element) {
    return String(element?.textContent || "").trim().replace(/\s+/g, " ");
  }

  function directChild(element, selector) {
    return Array.from(element?.children || []).find((child) => child.matches(selector)) || null;
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

  function setupStructureReview(root) {
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
  }

  function questionTermElement(term) {
    const item = document.createElement("li");
    const label = document.createElement("span");
    const badge = document.createElement("span");
    const termInput = document.createElement("input");
    const modeInput = document.createElement("input");
    const remove = document.createElement("button");
    const mode = termMode(term?.mode);

    item.className = "ebd-question-term";
    item.dataset.ebdQuestionTerm = "";
    label.textContent = String(term?.label || "");
    badge.className = `ebd-selected-term-badge is-${mode}`;
    badge.textContent = modeLabel(mode);
    termInput.type = "hidden";
    termInput.name = "ebd_keyword[]";
    termInput.value = String(term?.label || "");
    modeInput.type = "hidden";
    modeInput.name = "ebd_term_mode[]";
    modeInput.value = mode;
    remove.type = "button";
    remove.dataset.ebdQuestionRemove = "";
    remove.setAttribute("aria-label", `Remove ${termInput.value}`);
    remove.innerHTML = "&times;";
    item.append(label, badge, termInput, modeInput, remove);
    return item;
  }

  function updateQuestionReview(form) {
    const terms = form.querySelectorAll("[data-ebd-question-term]");
    const review = form.querySelector("[data-ebd-question-review]");
    const search = form.querySelector("[data-ebd-question-search]");
    if (review) review.hidden = terms.length === 0;
    if (search) search.disabled = terms.length === 0;
  }

  function setupQuestionForm(form) {
    const input = form.querySelector("[data-ebd-question-input]");
    const interpret = form.querySelector("[data-ebd-question-interpret]");
    const status = form.querySelector("[data-ebd-question-status]");
    const termList = form.querySelector("[data-ebd-question-terms]");
    const review = form.querySelector("[data-ebd-question-review]");
    const search = form.querySelector("[data-ebd-question-search]");
    const expanded = form.querySelector("[data-ebd-question-expanded]");
    const compact = form.querySelector("[data-ebd-question-compact]");
    const requestId = form.querySelector("[data-ebd-ai-request]");
    if (!input || !interpret || !status || !termList || !review) return;

    const setCollapsed = (collapsed) => {
      if (expanded) expanded.hidden = collapsed;
      if (compact) compact.hidden = !collapsed;
      form.classList.toggle("is-collapsed", collapsed);
    };

    const interpretQuestion = async () => {
      if (interpret.disabled || form.classList.contains("is-loading")) return;
      const question = input.value.trim();
      if (!question) {
        status.textContent = "Enter a question before asking for an interpretation.";
        input.focus();
        return;
      }
      const originalLabel = interpret.textContent;
      let submitted = false;
      interpret.disabled = true;
      interpret.textContent = "Interpreting...";
      interpret.setAttribute("aria-busy", "true");
      form.classList.add("is-loading");
      status.classList.add("is-loading");
      status.textContent = "Interpreting your question...";
      try {
        const response = await fetch(config.interpretUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ question }),
        });
        const payload = await response.json();
        if (!response.ok) {
          throw new Error(payload?.message || "The question could not be interpreted.");
        }
        termList.innerHTML = "";
        (payload.terms || []).forEach((term) => termList.appendChild(questionTermElement(term)));
        if (requestId) requestId.value = String(payload.request_id || "");
        updateQuestionReview(form);
        if (!termList.querySelector("[data-ebd-question-term]")) {
          status.textContent = "No matching topics or keywords were found. Try revising the question.";
          return;
        }
        status.textContent = "Searching posts...";
        submitted = true;
        form.requestSubmit(search || undefined);
      } catch (error) {
        status.textContent = error?.message || "The question could not be interpreted. Please try again.";
      } finally {
        if (!submitted) {
          form.classList.remove("is-loading");
          status.classList.remove("is-loading");
          interpret.disabled = false;
          interpret.textContent = originalLabel;
          interpret.removeAttribute("aria-busy");
        }
      }
    };

    interpret.addEventListener("click", interpretQuestion);

    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" || event.shiftKey || event.isComposing) return;
      event.preventDefault();
      interpretQuestion();
    });

    form.addEventListener("click", (event) => {
      if (event.target.closest("[data-ebd-question-clear]")) {
        input.value = "";
        termList.innerHTML = "";
        updateQuestionReview(form);
        status.textContent = "";
        window.location.assign(form.action);
        return;
      }
      if (event.target.closest("[data-ebd-question-expand]")) {
        setCollapsed(false);
        input.focus({ preventScroll: true });
        return;
      }
      const remove = event.target.closest("[data-ebd-question-remove]");
      if (remove) {
        remove.closest("[data-ebd-question-term]")?.remove();
        updateQuestionReview(form);
        return;
      }
      if (event.target.closest("[data-ebd-question-edit]")) {
        setCollapsed(false);
        input.focus({ preventScroll: true });
        input.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    });

    input.addEventListener("input", () => {
      status.textContent = "";
    });
    updateQuestionReview(form);
    setCollapsed(Boolean(compact && !compact.hidden));
    if (!input.value.trim()) input.focus({ preventScroll: true });
  }

  function setupSemanticForm(form) {
    const input = form.querySelector("[data-ebd-semantic-question]");
    const submit = form.querySelector("[data-ebd-semantic-submit]");
    const clear = form.querySelector("[data-ebd-semantic-clear]");
    const status = form.querySelector("[data-ebd-semantic-status]");
    const requestId = form.querySelector("[data-ebd-ai-request]");
    const results = form.closest(".ebd-discovery")?.querySelector("[data-ebd-results]");
    if (!input || !submit || !status || !results) return;
    form.ebdSemantic = true;

    const run = async () => {
      if (submit.disabled || form.classList.contains("is-loading") || !config.semanticUrl) return;
      const question = input.value.trim();
      if (!question) {
        status.textContent = "Enter a question before searching.";
        input.focus();
        return;
      }
      const originalLabel = submit.textContent;
      submit.disabled = true;
      submit.textContent = "Searching...";
      submit.setAttribute("aria-busy", "true");
      form.classList.add("is-loading");
      results.setAttribute("aria-busy", "true");
      status.classList.add("is-loading");
      status.textContent = "Comparing your question with post summaries and reviewing the strongest matches...";
      try {
        const response = await fetch(config.semanticUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ question }),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload?.message || "The semantic search could not be completed.");
        if (requestId) requestId.value = String(payload.request_id || "");
        const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
        form.ebdBroaderResult = semanticPageResult(payload.broader, 1, sort);
        form.ebdRefinedResult = payload.refined ? payload : null;
        form.ebdShowingBroader = !payload.refined;
        form.ebdResultsNotice = String(payload.notice || "");
        const displayed = payload.refined
          ? sortedRefinedResult(payload, sort)
          : form.ebdBroaderResult;
        renderResults(results, displayed, form);
        updateBrowserUrl(form);
        status.textContent = "";
        results.scrollIntoView({ behavior: "smooth", block: "start" });
      } catch (error) {
        status.textContent = error?.message || "The semantic search could not be completed. Please try again.";
      } finally {
        form.classList.remove("is-loading");
        results.removeAttribute("aria-busy");
        status.classList.remove("is-loading");
        submit.disabled = false;
        submit.textContent = originalLabel;
        submit.removeAttribute("aria-busy");
      }
    };

    form.addEventListener("submit", (event) => {
      event.preventDefault();
      run();
    });
    input.addEventListener("keydown", (event) => {
      if (event.key !== "Enter" || event.shiftKey || event.isComposing) return;
      event.preventDefault();
      run();
    });
    input.addEventListener("input", () => {
      status.textContent = "";
    });
    clear?.addEventListener("click", () => {
      input.value = "";
      window.location.assign(form.action);
    });
    form.querySelectorAll('input[name="ebd_sort"]').forEach((radio) => {
      radio.addEventListener("change", () => {
        if (form.ebdRefinedResult && !form.ebdShowingBroader) {
          renderResults(results, sortedRefinedResult(form.ebdRefinedResult, radio.value), form);
        } else if (form.ebdBroaderResult) {
          form.ebdBroaderResult = semanticPageResult(form.ebdBroaderResult, 1, radio.value);
          renderResults(results, form.ebdBroaderResult, form);
        }
        updateBrowserUrl(form);
      });
    });
    if (!input.value.trim()) input.focus({ preventScroll: true });
    if (form.dataset.ebdAutoRun === "true" && input.value.trim()) run();
  }

  document.querySelectorAll("[data-ebd-search-form]").forEach(setupForm);
  document.querySelectorAll("[data-ebd-question-form]").forEach(setupQuestionForm);
  document.querySelectorAll("[data-ebd-semantic-form]").forEach(setupSemanticForm);
  document.querySelectorAll("[data-ebd-description-mode]").forEach(setupDescriptionMode);
  document.querySelectorAll(".ebd-view-structure-review").forEach(setupStructureReview);
  setupTitleTooltips(document);

  document.querySelectorAll("[data-ebd-auto-refine]").forEach((pending) => {
    const discovery = pending.closest(".ebd-discovery");
    const form = discovery?.querySelector("[data-ebd-search-form],[data-ebd-semantic-form]");
    const results = discovery?.querySelector("[data-ebd-results]");
    if (form && results) refineSearch(form, results);
  });

  document.addEventListener("click", (event) => {
    const pagination = event.target.closest(".ebd-pagination-link[href]");
    const discovery = pagination?.closest(".ebd-discovery");
    const form = discovery?.querySelector("[data-ebd-search-form],[data-ebd-semantic-form]");
    if (pagination && form?.matches("[data-ebd-semantic-form]") && form.ebdBroaderResult && form.ebdShowingBroader) {
      event.preventDefault();
      const page = Number(new URL(pagination.href).searchParams.get("ebd_page") || 1);
      const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
      form.ebdBroaderResult = semanticPageResult(form.ebdBroaderResult, page, sort);
      const results = discovery.querySelector("[data-ebd-results]");
      if (results) renderResults(results, form.ebdBroaderResult, form);
      updateBrowserUrl(form, page);
      results?.scrollIntoView({ behavior: "smooth", block: "start" });
      return;
    }
    if (pagination && form?.ebdRefinedResult && form.ebdShowingBroader) {
      event.preventDefault();
      const page = Number(new URL(pagination.href).searchParams.get("ebd_page") || 1);
      refreshSearch(form, page, { preserveRefined: true });
      discovery.querySelector("[data-ebd-results]")?.scrollIntoView({ behavior: "smooth", block: "start" });
      return;
    }
    if (event.target.closest("[data-ebd-back-to-top]")) {
      const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
      window.scrollTo({ top: 0, left: 0, behavior: reducedMotion ? "auto" : "smooth" });
      return;
    }
    if (!event.target.closest(".ebd-keyword-input-wrap,.ebd-suggestion-order-row")) {
      document.querySelectorAll(".ebd-keyword-input").forEach(closeSuggestions);
    }
  });

  const statusButton = document.querySelector("[data-ebd-status-refresh]");
  const statusMessage = document.querySelector("[data-ebd-status-message]");
  if (statusButton && statusMessage && config.statusUrl) {
    statusButton.addEventListener("click", async () => {
      statusButton.disabled = true;
      statusMessage.textContent = "Checking status...";
      try {
        const response = await fetch(config.statusUrl);
        const status = await response.json();
        statusMessage.textContent = status.database_connected
          ? `WordPress is connected to MySQL. Import state: ${status.import_state}.`
          : "The status endpoint could not be reached.";
      } catch (_error) {
        statusMessage.textContent = "The status endpoint could not be reached.";
      } finally {
        statusButton.disabled = false;
      }
    });
  }
})();
