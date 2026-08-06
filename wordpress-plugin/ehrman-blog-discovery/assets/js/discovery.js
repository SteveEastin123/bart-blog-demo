(function () {
  "use strict";

  const config = window.EhrmanDiscovery || {};
  const strings = config.strings || {};
  const MAX_TERMS = 4;
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
    const found = [];
    const seen = new Set();
    form.querySelectorAll('input[name="ebd_keyword[]"]').forEach((input) => {
      if (input === exceptInput) return;
      const value = input.value.trim();
      const key = normalized(value);
      if (key && !seen.has(key)) {
        seen.add(key);
        found.push(value);
      }
    });
    return found.slice(0, MAX_TERMS);
  }

  function activeCategory(form) {
    const filter = form.querySelector("[data-ebd-category]");
    return filter ? filter.value.trim() : (form.dataset.category || "");
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
    selected.forEach((value) => url.searchParams.append("selected[]", value));
    if (category) url.searchParams.set("category", category);
    if (topic) url.searchParams.set("topic", topic);

    try {
      const response = await fetch(url, { signal: controller.signal, headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error("Suggestion request failed");
      const suggestions = await response.json();
      if (input.ebdAbortController !== controller) return [];
      renderSuggestions(input, Array.isArray(suggestions) ? suggestions : []);
      return Array.isArray(suggestions) ? suggestions : [];
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
      badge.className = `ebd-suggestion-badge ${suggestion.isTopic ? "is-topic" : "is-keyword"}`;
      badge.textContent = suggestion.isTopic ? (strings.topic || "Topic") : (strings.keyword || "Keyword");
      count.className = "ebd-suggestion-count";
      count.textContent = `${suggestion.postCount} ${postWord(suggestion.postCount)}`;
      main.append(label, badge);
      button.append(main, count);
      button.addEventListener("mousedown", (event) => event.preventDefault());
      button.addEventListener("click", () => selectSuggestion(input, suggestion));
      if (suggestion.isTopic && suggestion.description) {
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
    if (addChip(form, suggestion.label)) {
      closeSuggestions(input);
      refreshSearch(form);
      searchInput(form)?.focus({ preventScroll: true });
    }
  }

  function addChip(form, value) {
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
    const label = document.createElement("span");
    label.textContent = clean;
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "ebd-keyword-remove";
    remove.dataset.ebdRemove = "";
    remove.setAttribute("aria-label", `Remove ${clean}`);
    remove.innerHTML = "&times;";
    chip.append(hidden, label, remove);
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
  }

  function requestUrl(form) {
    const url = new URL(config.searchUrl, window.location.origin);
    values(form, searchInput(form)).forEach((value) => url.searchParams.append("term[]", value));
    const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
    url.searchParams.set("sort", sort);
    const category = activeCategory(form);
    if (category) url.searchParams.set("category", category);
    if (form.dataset.topic) url.searchParams.set("topic", form.dataset.topic);
    return url;
  }

  function updateBrowserUrl(form) {
    const url = new URL(form.action, window.location.origin);
    url.searchParams.delete("ebd_keyword[]");
    url.searchParams.delete("ebd_keyword");
    url.searchParams.delete("ebd_sort");
    values(form, searchInput(form)).forEach((value) => url.searchParams.append("ebd_keyword[]", value));
    const sort = form.querySelector('input[name="ebd_sort"]:checked')?.value || "ranked";
    if (sort !== "ranked") url.searchParams.set("ebd_sort", sort);
    const categoryInput = form.querySelector("[data-ebd-category]");
    if (categoryInput) {
      url.searchParams.delete("ebd_category");
      if (categoryInput.value) url.searchParams.set("ebd_category", categoryInput.value);
    }
    window.history.replaceState({}, "", `${url.pathname}${url.search}`);
  }

  async function refreshSearch(form) {
    const results = form.parentElement?.querySelector("[data-ebd-results]")
      || form.closest(".ebd-discovery")?.querySelector("[data-ebd-results]");
    if (!results || !config.searchUrl) return;
    if (form.ebdSearchController) form.ebdSearchController.abort();
    const controller = new AbortController();
    form.ebdSearchController = controller;
    form.classList.add("is-loading");
    results.setAttribute("aria-busy", "true");
    try {
      const response = await fetch(requestUrl(form), { signal: controller.signal, headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error("Search request failed");
      const result = await response.json();
      if (form.ebdSearchController !== controller) return;
      renderResults(results, result);
      const headingCount = form.closest(".ebd-discovery")?.querySelector("[data-ebd-result-count]");
      if (headingCount) {
        const count = Number(result.count || 0);
        headingCount.textContent = `${count} ${postWord(count)}`;
      }
      updateBrowserUrl(form);
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

  function renderResults(container, result) {
    container.innerHTML = "";
    const count = Number(result.count || 0);
    const terms = Array.isArray(result.terms) ? result.terms : [];
    const context = container.dataset.context || "";
    const summary = document.createElement("p");
    summary.className = "ebd-results-summary";
    summary.setAttribute("aria-live", "polite");
    const countStrong = document.createElement("strong");
    countStrong.textContent = `${count} ${postWord(count)}`;
    summary.append(countStrong);
    const contextIsTerm = terms.some((term) => normalized(term) === normalized(context));
    if (context && !contextIsTerm) {
      summary.append(document.createTextNode(" in "));
      const contextStrong = document.createElement("strong");
      contextStrong.textContent = context;
      summary.append(contextStrong);
    }
    if (terms.length) {
      summary.append(document.createTextNode(` ${count === 1 ? "matches" : "match"} `));
      const termStrong = document.createElement("strong");
      termStrong.textContent = terms.join(" + ");
      summary.append(termStrong);
    }
    summary.append(document.createTextNode("."));
    container.append(summary);
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
      title.target = "_blank";
      title.rel = "noopener";
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
    applyDescriptionToggle(container.closest(".ebd-discovery") || document);
    setupTitleTooltips(container);
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

  function setupForm(form) {
    const input = searchInput(form);
    setupCategory(form);
    updateSlots(form);
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
      radio.addEventListener("change", () => refreshSearch(form));
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
          .sort((left, right) => Number(right.isTopic) - Number(left.isTopic))[0];
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
        const toggle = title.closest(".ebd-discovery")?.querySelector("[data-ebd-description-toggle]");
        if (!toggle?.checked) showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
      });
      hoverTarget.addEventListener("mouseleave", hideTooltip);
      title.addEventListener("focus", () => {
        const toggle = title.closest(".ebd-discovery")?.querySelector("[data-ebd-description-toggle]");
        if (!toggle?.checked) showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
      });
      title.addEventListener("blur", hideTooltip);
    });
  }

  function applyDescriptionToggle(root) {
    const toggle = root.querySelector("[data-ebd-description-toggle]");
    if (!toggle) return;
    root.querySelectorAll(".ebd-item-description,.ebd-post-description").forEach((description) => {
      description.hidden = !toggle.checked;
    });
    if (toggle.checked) hideTooltip();
  }

  document.querySelectorAll("[data-ebd-search-form]").forEach(setupForm);
  document.querySelectorAll("[data-ebd-description-toggle]").forEach((toggle) => {
    const root = toggle.closest(".ebd-discovery") || document;
    const key = `ehrman-discovery-descriptions-${toggle.dataset.scope || "default"}`;
    try {
      const stored = window.sessionStorage.getItem(key);
      if (stored !== null) toggle.checked = stored === "true";
    } catch (_error) {}
    toggle.addEventListener("change", () => {
      try { window.sessionStorage.setItem(key, String(toggle.checked)); } catch (_error) {}
      applyDescriptionToggle(root);
    });
    applyDescriptionToggle(root);
  });
  setupTitleTooltips(document);

  document.addEventListener("click", (event) => {
    if (!event.target.closest(".ebd-keyword-input-wrap")) {
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
