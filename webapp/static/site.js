(function () {
  const MAX_KEYWORDS = 4;

  function uniqueKeywordValues(values) {
    const uniqueValues = [];
    const seen = new Set();
    values.forEach((value) => {
      const cleanValue = value.trim();
      const key = cleanValue.toLowerCase();
      if (!cleanValue || seen.has(key)) return;
      seen.add(key);
      uniqueValues.push(cleanValue);
    });
    return uniqueValues;
  }

  function selectedValues(form, exceptInput) {
    return uniqueKeywordValues(
      Array.from(form.querySelectorAll('input[name="keyword"]'))
        .filter((input) => input !== exceptInput)
        .map((input) => input.value)
    );
  }

  function categoryFilter(form) {
    return form ? form.querySelector("[data-category-filter]") : null;
  }

  function activeCategorySlug(form) {
    const filter = categoryFilter(form);
    return filter ? filter.value.trim() : (form?.dataset.categorySlug || "");
  }

  function categoryCombobox(form) {
    return form ? form.querySelector("[data-category-combobox]") : null;
  }

  function closeCategoryCombobox(combobox, restoreFocus = false) {
    if (!combobox) return;
    const toggle = combobox.querySelector("[data-category-toggle]");
    const options = combobox.querySelector("[data-category-options]");
    if (options) options.hidden = true;
    if (toggle) {
      toggle.setAttribute("aria-expanded", "false");
      if (restoreFocus) toggle.focus({ preventScroll: true });
    }
  }

  function syncCategoryCombobox(form) {
    const filter = categoryFilter(form);
    const combobox = categoryCombobox(form);
    if (!filter || !combobox) return;
    const optionButtons = Array.from(combobox.querySelectorAll("[data-category-option]"));
    const selected = optionButtons.find((option) => option.dataset.value === filter.value)
      || optionButtons[0];
    optionButtons.forEach((option) => {
      option.setAttribute("aria-selected", String(option === selected));
    });
    const name = combobox.querySelector("[data-category-current-name]");
    const count = combobox.querySelector("[data-category-current-count]");
    if (name) name.textContent = selected?.dataset.label || "All categories";
    if (count) count.textContent = selected?.dataset.count || "";
  }

  function openCategoryCombobox(combobox, focusDirection = 0) {
    if (!combobox) return;
    document.querySelectorAll(".keyword-suggestion-list").forEach(resetKeywordSuggestionList);
    document.querySelectorAll("[data-category-combobox]").forEach((other) => {
      if (other !== combobox) closeCategoryCombobox(other);
    });
    const toggle = combobox.querySelector("[data-category-toggle]");
    const options = combobox.querySelector("[data-category-options]");
    if (!toggle || !options) return;
    options.hidden = false;
    toggle.setAttribute("aria-expanded", "true");
    if (!focusDirection) return;
    const optionButtons = Array.from(options.querySelectorAll("[data-category-option]"));
    const selectedIndex = optionButtons.findIndex((option) => option.getAttribute("aria-selected") === "true");
    const targetIndex = focusDirection < 0
      ? optionButtons.length - 1
      : Math.max(0, selectedIndex);
    optionButtons[targetIndex]?.focus({ preventScroll: true });
  }

  function setupCategoryCombobox(form) {
    const filter = categoryFilter(form);
    const combobox = categoryCombobox(form);
    if (!filter || !combobox) return;
    const toggle = combobox.querySelector("[data-category-toggle]");
    const options = combobox.querySelector("[data-category-options]");
    if (!toggle || !options) return;
    syncCategoryCombobox(form);
    toggle.addEventListener("click", (event) => {
      event.stopPropagation();
      if (options.hidden) openCategoryCombobox(combobox);
      else closeCategoryCombobox(combobox);
    });
    toggle.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        openCategoryCombobox(combobox, event.key === "ArrowUp" ? -1 : 1);
        return;
      }
      if (event.key === "Escape") {
        event.preventDefault();
        closeCategoryCombobox(combobox);
      }
    });
    options.addEventListener("click", (event) => {
      const option = event.target.closest("[data-category-option]");
      if (!option || !options.contains(option)) return;
      filter.value = option.dataset.value || "";
      syncCategoryCombobox(form);
      closeCategoryCombobox(combobox, true);
      filter.dispatchEvent(new Event("change", { bubbles: true }));
    });
    options.addEventListener("keydown", (event) => {
      const option = event.target.closest("[data-category-option]");
      if (!option) return;
      const optionButtons = Array.from(options.querySelectorAll("[data-category-option]"));
      const index = optionButtons.indexOf(option);
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const direction = event.key === "ArrowDown" ? 1 : -1;
        optionButtons[(index + direction + optionButtons.length) % optionButtons.length]
          ?.focus({ preventScroll: true });
        return;
      }
      if (event.key === "Home" || event.key === "End") {
        event.preventDefault();
        optionButtons[event.key === "Home" ? 0 : optionButtons.length - 1]
          ?.focus({ preventScroll: true });
        return;
      }
      if (event.key === "Escape") {
        event.preventDefault();
        closeCategoryCombobox(combobox, true);
      }
    });
  }

  let topicTooltipElement = null;

  function topicTooltip() {
    if (!topicTooltipElement) {
      topicTooltipElement = document.createElement("div");
      topicTooltipElement.id = "keyword-topic-description-tooltip";
      topicTooltipElement.className = "topic-suggestion-tooltip";
      topicTooltipElement.setAttribute("role", "tooltip");
      topicTooltipElement.hidden = true;
      document.body.appendChild(topicTooltipElement);
    }
    return topicTooltipElement;
  }

  function hideTopicTooltip() {
    if (!topicTooltipElement) return;
    topicTooltipElement.hidden = true;
    topicTooltipElement.textContent = "";
  }

  function showTopicTooltip(button, description) {
    if (!button || !description) {
      hideTopicTooltip();
      return;
    }
    const tooltip = topicTooltip();
    tooltip.textContent = description;
    tooltip.hidden = false;

    const buttonRect = button.getBoundingClientRect();
    const padding = 16;
    const gap = 10;
    const tooltipWidth = tooltip.offsetWidth;
    const tooltipHeight = tooltip.offsetHeight;
    let left = buttonRect.right + gap;
    if (left + tooltipWidth > window.innerWidth - padding) {
      left = buttonRect.left - tooltipWidth - gap;
    }
    if (left < padding) {
      left = Math.max(padding, Math.min(buttonRect.left, window.innerWidth - tooltipWidth - padding));
    }
    const top = Math.max(
      padding,
      Math.min(buttonRect.top, window.innerHeight - tooltipHeight - padding)
    );
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
  }

  function resetKeywordSuggestionList(list) {
    if (!list) return;
    hideTopicTooltip();
    list.hidden = true;
    list.removeAttribute("aria-label");
    list.classList.remove("open-above", "use-page-scroll");
    if (list.parentElement) {
      list.parentElement.style.marginBottom = "";
    }
    list.style.left = "";
    list.style.maxHeight = "";
    list.style.top = "";
    list.style.width = "";
    list.style.removeProperty("--keyword-suggestion-width");
    const input = list.parentElement?.querySelector(".keyword-input");
    if (input) {
      input.keywordSuggestionMatches = [];
      input.keywordSuggestionIndex = -1;
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    }
  }

  function positionKeywordSuggestionList(input) {
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!list || list.hidden) return;
    list.classList.remove("open-above");
    const rect = input.getBoundingClientRect();
    const preferredWidth = window.innerWidth > 700 ? Math.max(rect.width, 540) : rect.width;
    const width = Math.min(preferredWidth, window.innerWidth - 32);
    const usePageScroll = list.getAttribute("aria-label") === "Featured Topics";
    list.classList.toggle("use-page-scroll", usePageScroll);
    input.parentElement.style.marginBottom = "";
    if (usePageScroll) {
      list.style.left = "0px";
      list.style.maxHeight = "none";
      list.style.top = `${input.offsetHeight + 4}px`;
      list.style.width = `${width}px`;
      list.style.setProperty("--keyword-suggestion-width", `${width}px`);
      input.parentElement.style.marginBottom = `${list.scrollHeight + 8}px`;
      return;
    }
    const below = window.innerHeight - rect.bottom - 16;
    const available = Math.max(120, Math.min(below, window.innerHeight - 32, 720));
    const left = Math.max(16, Math.min(rect.left, window.innerWidth - width - 16));
    const top = Math.min(window.innerHeight - 16, rect.bottom + 4);
    list.style.left = `${left}px`;
    list.style.maxHeight = `${available}px`;
    list.style.top = `${top}px`;
    list.style.width = `${width}px`;
    list.style.setProperty("--keyword-suggestion-width", `${width}px`);
  }

  function appendHighlightedSuggestionText(container, label, query) {
    const text = String(label || "");
    const match = String(query || "").trim();
    const matchIndex = match ? text.toLocaleLowerCase().indexOf(match.toLocaleLowerCase()) : -1;
    if (matchIndex < 0) {
      container.textContent = text;
      return;
    }
    container.append(document.createTextNode(text.slice(0, matchIndex)));
    const strong = document.createElement("strong");
    strong.className = "suggestion-match";
    strong.textContent = text.slice(matchIndex, matchIndex + match.length);
    container.append(strong, document.createTextNode(text.slice(matchIndex + match.length)));
  }

  function exactKeywordSuggestion(input, suggestions) {
    const query = input.value.trim().toLocaleLowerCase();
    if (!query) return null;
    return suggestions
      .filter((suggestion) => suggestion.label.trim().toLocaleLowerCase() === query)
      .sort((left, right) => Number(right.isTopic) - Number(left.isTopic))[0] || null;
  }

  function chooseKeywordSuggestion(input, suggestion) {
    if (!suggestion) return false;
    const form = input.closest("[data-keyword-form]");
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!form) return false;
    const added = addKeywordChip(form, input, suggestion.label);
    resetKeywordSuggestionList(list);
    if (added) {
      window.location.href = keywordSearchUrl(form, input);
    }
    return added;
  }

  function setActiveKeywordSuggestion(input, index) {
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    const suggestions = input.keywordSuggestionMatches || [];
    const buttons = list ? Array.from(list.querySelectorAll("button[data-suggestion-index]")) : [];
    if (!list || list.hidden || !suggestions.length || !buttons.length) return;
    const nextIndex = (index + suggestions.length) % suggestions.length;
    input.keywordSuggestionIndex = nextIndex;
    buttons.forEach((button, buttonIndex) => {
      const active = buttonIndex === nextIndex;
      button.classList.toggle("is-active", active);
      button.closest("li")?.setAttribute("aria-selected", String(active));
    });
    const activeButton = buttons[nextIndex];
    input.setAttribute("aria-activedescendant", activeButton.closest("li")?.id || "");
    activeButton.scrollIntoView({ block: "nearest" });
  }

  async function fetchSuggestions(input) {
    const form = input.closest("[data-keyword-form]");
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!form || !list) return;
    const query = input.value.trim();
    const categorySlug = activeCategorySlug(form);
    if (!query && selectedValues(form, input).length === 0 && !categorySlug) {
      input.keywordSuggestionMatches = [];
      input.keywordSuggestionIndex = -1;
      resetKeywordSuggestionList(list);
      return;
    }
    const params = new URLSearchParams();
    params.set("q", query);
    selectedValues(form, input).forEach((value) => params.append("selected", value));
    if (categorySlug) {
      params.set("category", categorySlug);
    }
    if (form.dataset.topicSlug) {
      params.set("topic", form.dataset.topicSlug);
    }
    const response = await fetch("/api/keywords?" + params.toString());
    const suggestions = await response.json();
    list.innerHTML = "";
    input.keywordSuggestionMatches = suggestions;
    input.keywordSuggestionIndex = -1;
    if (!suggestions.length) {
      resetKeywordSuggestionList(list);
      return;
    }
    list.removeAttribute("aria-label");
    suggestions.forEach((suggestion, index) => {
      const item = document.createElement("li");
      const button = document.createElement("button");
      const main = document.createElement("span");
      const label = document.createElement("span");
      const type = document.createElement("span");
      const count = document.createElement("span");
      button.type = "button";
      button.dataset.suggestionIndex = String(index);
      item.id = `${list.id}-option-${index}`;
      item.setAttribute("role", "option");
      item.setAttribute("aria-selected", "false");
      main.className = "suggestion-main";
      label.className = "suggestion-label";
      appendHighlightedSuggestionText(label, suggestion.label, input.value);
      type.className = `suggestion-type ${suggestion.isTopic ? "is-topic" : "is-keyword"}`;
      type.textContent = suggestion.isTopic ? "Topic" : "Keyword";
      count.className = "suggestion-count";
      count.textContent = `${suggestion.postCount} ${suggestion.postCount === 1 ? "post" : "posts"}`;
      main.append(label, type);
      button.append(main, count);
      if (suggestion.isTopic && suggestion.description) {
        button.setAttribute("aria-describedby", "keyword-topic-description-tooltip");
        button.addEventListener("mouseenter", () => showTopicTooltip(button, suggestion.description));
        button.addEventListener("mouseleave", hideTopicTooltip);
        button.addEventListener("focus", () => showTopicTooltip(button, suggestion.description));
        button.addEventListener("blur", hideTopicTooltip);
      }
      button.addEventListener("mousedown", (event) => {
        event.preventDefault();
      });
      button.addEventListener("click", () => {
        chooseKeywordSuggestion(input, suggestion);
      });
      item.appendChild(button);
      list.appendChild(item);
    });
    list.hidden = false;
    input.setAttribute("aria-expanded", "true");
    positionKeywordSuggestionList(input);
  }

  function keywordChipList(form) {
    return form.querySelector("[data-keyword-chip-list]");
  }

  function keywordEntryWrap(form) {
    return form.querySelector(".keyword-input-wrap");
  }

  function updateKeywordEntryState(form) {
    if (!form) return;
    const input = form.querySelector(".keyword-input");
    if (!input) return;
    const values = selectedValues(form, input);
    input.placeholder = `Keyword ${Math.min(values.length + 1, MAX_KEYWORDS)}`;
    input.disabled = values.length >= MAX_KEYWORDS;
    const filter = categoryFilter(form);
    const hasCategory = Boolean(filter?.value.trim());
    const clearButton = form.querySelector("[data-clear-keywords]");
    if (clearButton) {
      clearButton.disabled = values.length === 0 && !input.value.trim() && !hasCategory;
    }
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = values.length === 0 && !hasCategory;
    }
    const wrap = keywordEntryWrap(form);
    if (wrap) {
      wrap.hidden = values.length >= MAX_KEYWORDS;
    }
    const chipList = keywordChipList(form);
    if (chipList) {
      chipList.querySelectorAll(".keyword-empty-slot").forEach((slot) => slot.remove());
      const activeSlotCount = values.length >= MAX_KEYWORDS ? 0 : 1;
      const emptySlotCount = Math.max(0, MAX_KEYWORDS - values.length - activeSlotCount);
      const startIndex = values.length + activeSlotCount + 1;
      for (let index = 0; index < emptySlotCount; index += 1) {
        const emptySlot = document.createElement("span");
        emptySlot.className = "keyword-slot keyword-empty-slot";
        emptySlot.textContent = `Keyword ${startIndex + index}`;
        chipList.appendChild(emptySlot);
      }
    }
  }

  function keywordSearchUrl(form, input) {
    const target = new URL(form.getAttribute("action") || "/keyword-results", window.location.origin);
    const params = target.searchParams;
    params.delete("keyword");
    params.delete("sort");
    params.delete("category");
    uniqueKeywordValues([...selectedValues(form, input), input.value]).forEach((value) =>
      params.append("keyword", value)
    );
    const selectedSort = form.querySelector('input[name="sort"]:checked');
    if (selectedSort && selectedSort.value && selectedSort.value !== "ranked") {
      params.set("sort", selectedSort.value);
    }
    const selectedCategory = categoryFilter(form)?.value.trim() || "";
    if (selectedCategory) {
      params.set("category", selectedCategory);
    }
    if (!params.has("keyword") && !selectedCategory && target.pathname === "/keyword-results") {
      return "/keyword-search";
    }
    const queryString = params.toString();
    return target.pathname + (queryString ? `?${queryString}` : "");
  }

  function addKeywordChip(form, input, value) {
    const cleanValue = value.trim();
    if (!cleanValue) return false;
    const currentValues = selectedValues(form, input);
    if (currentValues.some((currentValue) => currentValue.toLowerCase() === cleanValue.toLowerCase())) {
      input.value = "";
      updateKeywordEntryState(form);
      return false;
    }
    if (currentValues.length >= MAX_KEYWORDS) {
      input.value = "";
      updateKeywordEntryState(form);
      return false;
    }
    const chipList = keywordChipList(form);
    if (!chipList) return false;
    const chip = document.createElement("span");
    chip.className = "keyword-slot keyword-chip";
    const hidden = document.createElement("input");
    hidden.type = "hidden";
    hidden.name = "keyword";
    hidden.value = cleanValue;
    const label = document.createElement("span");
    label.textContent = cleanValue;
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "keyword-chip-remove";
    remove.dataset.removeKeyword = "";
    remove.setAttribute("aria-label", `Remove ${cleanValue}`);
    remove.textContent = "x";
    chip.append(hidden, label, remove);
    const wrap = keywordEntryWrap(form);
    chipList.insertBefore(chip, wrap || chipList.querySelector(".keyword-empty-slot"));
    input.value = "";
    updateKeywordEntryState(form);
    return true;
  }

  document.querySelectorAll("[data-keyword-form]").forEach((form) => {
    setupCategoryCombobox(form);
    updateKeywordEntryState(form);
    form.addEventListener("submit", (event) => {
      const input = form.querySelector(".keyword-input");
      if (!input) return;
      event.preventDefault();
      if (!selectedValues(form, input).length && !categoryFilter(form)?.value.trim()) return;
      window.location.href = keywordSearchUrl(form, input);
    });
    form.addEventListener("change", (event) => {
      const categorySelect = event.target.closest("[data-category-filter]");
      if (categorySelect && form.contains(categorySelect)) {
        const input = form.querySelector(".keyword-input");
        if (!input) return;
        if (categorySelect.value) {
          form.dataset.categorySlug = categorySelect.value;
        } else {
          delete form.dataset.categorySlug;
        }
        updateKeywordEntryState(form);
        document.querySelectorAll(".keyword-suggestion-list").forEach(resetKeywordSuggestionList);
        window.location.href = keywordSearchUrl(form, input);
        return;
      }
      const sortInput = event.target.closest('input[name="sort"]');
      if (!sortInput || !form.contains(sortInput)) return;
      const input = form.querySelector(".keyword-input");
      if (!input) return;
      if (form.dataset.refreshOnRemove === "true") {
        window.location.href = keywordSearchUrl(form, input);
        return;
      }
      if (form.dataset.sortCurrentPage === "true") {
        const url = new URL(window.location.href);
        if (sortInput.value === "ranked") {
          url.searchParams.delete("sort");
        } else {
          url.searchParams.set("sort", sortInput.value);
        }
        window.location.href = url.toString();
      }
    });
    form.addEventListener("click", (event) => {
      const clearButton = event.target.closest("[data-clear-keywords]");
      if (clearButton && form.contains(clearButton)) {
        event.preventDefault();
        keywordChipList(form)?.querySelectorAll(".keyword-chip").forEach((chip) => chip.remove());
        const filter = categoryFilter(form);
        if (filter) {
          filter.value = "";
          delete form.dataset.categorySlug;
          syncCategoryCombobox(form);
        }
        const input = form.querySelector(".keyword-input");
        if (!input) return;
        input.value = "";
        updateKeywordEntryState(form);
        input.focus({ preventScroll: true });
        if (form.dataset.refreshOnRemove === "true") {
          window.location.href = keywordSearchUrl(form, input);
          return;
        }
        fetchSuggestions(input);
        return;
      }
      const removeButton = event.target.closest("[data-remove-keyword]");
      if (!removeButton || !form.contains(removeButton)) return;
      removeButton.closest(".keyword-chip")?.remove();
      updateKeywordEntryState(form);
      const input = form.querySelector(".keyword-input");
      if (!input) return;
      input.focus({ preventScroll: true });
      if (form.dataset.refreshOnRemove === "true") {
        window.location.href = keywordSearchUrl(form, input);
        return;
      }
      fetchSuggestions(input);
    });
  });

  document.querySelectorAll(".keyword-input").forEach((input) => {
    let timer = null;
    const showSuggestions = () => {
      fetchSuggestions(input);
    };
    input.addEventListener("input", () => {
      clearTimeout(timer);
      updateKeywordEntryState(input.closest("[data-keyword-form]"));
      timer = setTimeout(() => fetchSuggestions(input), 90);
    });
    input.addEventListener("focus", showSuggestions);
    input.addEventListener("click", showSuggestions);
    input.addEventListener("keydown", (event) => {
      const list = input.parentElement.querySelector(".keyword-suggestion-list");
      const suggestions = input.keywordSuggestionMatches || [];
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        const direction = event.key === "ArrowDown" ? 1 : -1;
        if (list?.hidden || !suggestions.length) {
          fetchSuggestions(input).then(() => {
            const startIndex = direction > 0
              ? 0
              : (input.keywordSuggestionMatches || []).length - 1;
            setActiveKeywordSuggestion(input, startIndex);
          });
        } else {
          setActiveKeywordSuggestion(input, (input.keywordSuggestionIndex ?? -1) + direction);
        }
        return;
      }
      if (event.key === "Enter") {
        event.preventDefault();
        if (suggestions.length && input.keywordSuggestionIndex >= 0) {
          chooseKeywordSuggestion(input, suggestions[input.keywordSuggestionIndex]);
          return;
        }
        const exactSuggestion = exactKeywordSuggestion(input, suggestions);
        if (exactSuggestion) {
          chooseKeywordSuggestion(input, exactSuggestion);
          return;
        }
        if (input.value.trim()) {
          const enteredValue = input.value;
          fetchSuggestions(input).then(() => {
            if (input.value !== enteredValue) return;
            chooseKeywordSuggestion(
              input,
              exactKeywordSuggestion(input, input.keywordSuggestionMatches || [])
            );
          });
        }
        return;
      }
      if (event.key === "Tab") {
        resetKeywordSuggestionList(list);
        return;
      }
      if (event.key === "Escape") {
        resetKeywordSuggestionList(list);
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (!event.target.closest("[data-category-combobox]")) {
      document.querySelectorAll("[data-category-combobox]").forEach((combobox) => {
        closeCategoryCombobox(combobox);
      });
    }
    if (!event.target.closest(".keyword-input-wrap")) {
      document.querySelectorAll(".keyword-suggestion-list").forEach((list) => {
        resetKeywordSuggestionList(list);
      });
    }
  });

  function repositionOpenKeywordLists() {
    hideTopicTooltip();
    document.querySelectorAll(".keyword-input").forEach(positionKeywordSuggestionList);
  }

  window.addEventListener("resize", repositionOpenKeywordLists);
  window.addEventListener("scroll", repositionOpenKeywordLists, true);

  document.querySelectorAll(".post-title, .item-title").forEach((title) => {
    title.setAttribute("data-tooltip", title.getAttribute("data-description") || "");
  });

  document.querySelectorAll("[data-description-toggle]").forEach((checkbox) => {
    const contentPage = checkbox.closest(".content-page") || document;
    const descriptions = contentPage.querySelectorAll(".post-description, .item-description");
    const describedTitles = contentPage.querySelectorAll(".post-title, .item-title");
    const applyDescriptionState = () => {
      const show = checkbox.checked;
      descriptions.forEach((description) => {
        description.hidden = !show;
      });
      describedTitles.forEach((title) => {
        if (show) {
          title.removeAttribute("data-tooltip");
        } else {
          title.setAttribute("data-tooltip", title.getAttribute("data-description") || "");
        }
      });
    };
    checkbox.addEventListener("change", () => {
      applyDescriptionState();
    });
    applyDescriptionState();
  });
})();
