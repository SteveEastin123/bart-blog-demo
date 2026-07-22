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

  function resetKeywordSuggestionList(list) {
    if (!list) return;
    list.hidden = true;
    list.classList.remove("open-above");
    list.style.left = "";
    list.style.maxHeight = "";
    list.style.top = "";
    list.style.width = "";
    list.style.removeProperty("--keyword-suggestion-width");
  }

  function positionKeywordSuggestionList(input) {
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!list || list.hidden) return;
    list.classList.remove("open-above");
    const rect = input.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom - 16;
    const above = rect.top - 16;
    const openAbove = below < 320 && above > below;
    const available = Math.max(180, Math.min(openAbove ? above : below, window.innerHeight - 32, 720));
    const width = Math.min(rect.width, window.innerWidth - 32);
    const left = Math.max(16, Math.min(rect.left, window.innerWidth - width - 16));
    const top = openAbove
      ? Math.max(16, rect.top - available - 4)
      : Math.min(window.innerHeight - 16, rect.bottom + 4);
    if (openAbove) {
      list.classList.add("open-above");
    }
    list.style.left = `${left}px`;
    list.style.maxHeight = `${available}px`;
    list.style.top = `${top}px`;
    list.style.width = `${width}px`;
    list.style.setProperty("--keyword-suggestion-width", `${width}px`);
  }

  async function fetchSuggestions(input) {
    const form = input.closest("[data-keyword-form]");
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!form || !list) return;
    const params = new URLSearchParams();
    params.set("q", input.value.trim());
    selectedValues(form, input).forEach((value) => params.append("selected", value));
    const response = await fetch("/api/keywords?" + params.toString());
    const suggestions = await response.json();
    list.innerHTML = "";
    if (!suggestions.length) {
      resetKeywordSuggestionList(list);
      return;
    }
    suggestions.forEach((suggestion) => {
      const item = document.createElement("li");
      const button = document.createElement("button");
      button.type = "button";
      button.innerHTML = `<span>${suggestion.label}</span><span class="suggestion-count">${suggestion.postCount} posts</span>`;
      button.addEventListener("mousedown", (event) => {
        event.preventDefault();
        addKeywordChip(form, input, suggestion.label);
        resetKeywordSuggestionList(list);
        input.focus({ preventScroll: true });
      });
      item.appendChild(button);
      list.appendChild(item);
    });
    list.hidden = false;
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
    const clearButton = form.querySelector("[data-clear-keywords]");
    if (clearButton) {
      clearButton.disabled = values.length === 0 && !input.value.trim();
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
    const params = new URLSearchParams();
    uniqueKeywordValues([...selectedValues(form, input), input.value]).forEach((value) =>
      params.append("keyword", value)
    );
    const selectedSort = form.querySelector('input[name="sort"]:checked');
    if (selectedSort && selectedSort.value && selectedSort.value !== "ranked") {
      params.set("sort", selectedSort.value);
    }
    const queryString = params.toString();
    return queryString ? `/keyword-results?${queryString}` : "/keyword-search";
  }

  function addKeywordChip(form, input, value) {
    const cleanValue = value.trim();
    if (!cleanValue) return;
    const currentValues = selectedValues(form, input);
    if (currentValues.some((currentValue) => currentValue.toLowerCase() === cleanValue.toLowerCase())) {
      input.value = "";
      updateKeywordEntryState(form);
      return;
    }
    if (currentValues.length >= MAX_KEYWORDS) {
      input.value = "";
      updateKeywordEntryState(form);
      return;
    }
    const chipList = keywordChipList(form);
    if (!chipList) return;
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
  }

  document.querySelectorAll("[data-keyword-form]").forEach((form) => {
    updateKeywordEntryState(form);
    form.addEventListener("submit", (event) => {
      const input = form.querySelector(".keyword-input");
      if (!input) return;
      event.preventDefault();
      window.location.href = keywordSearchUrl(form, input);
    });
    form.addEventListener("change", (event) => {
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
      if (event.key === "Tab") {
        const list = input.parentElement.querySelector(".keyword-suggestion-list");
        resetKeywordSuggestionList(list);
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (!event.target.closest(".keyword-input-wrap")) {
      document.querySelectorAll(".keyword-suggestion-list").forEach((list) => {
        resetKeywordSuggestionList(list);
      });
    }
  });

  function repositionOpenKeywordLists() {
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
    checkbox.addEventListener("change", applyDescriptionState);
    applyDescriptionState();
  });
})();
