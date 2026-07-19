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

  function isFirstKeywordInput(input) {
    const form = input.closest("[data-keyword-form]");
    if (!form) return false;
    return Array.from(form.querySelectorAll(".keyword-input"))[0] === input;
  }

  async function fetchSuggestions(input) {
    const form = input.closest("[data-keyword-form]");
    const list = input.parentElement.querySelector(".keyword-suggestion-list");
    if (!form || !list) return;
    if (isFirstKeywordInput(input) && !input.value.trim() && !selectedValues(form, input).length) {
      list.hidden = true;
      return;
    }
    const params = new URLSearchParams();
    params.set("q", input.value.trim());
    selectedValues(form, input).forEach((value) => params.append("selected", value));
    const response = await fetch("/api/keywords?" + params.toString());
    const suggestions = await response.json();
    list.innerHTML = "";
    if (!suggestions.length) {
      list.hidden = true;
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
        list.hidden = true;
        input.focus({ preventScroll: true });
      });
      item.appendChild(button);
      list.appendChild(item);
    });
    list.hidden = false;
  }

  function keywordChipList(form) {
    return form.querySelector("[data-keyword-chip-list]");
  }

  function keywordEntryWrap(form) {
    return form.querySelector(".keyword-input-wrap");
  }

  function updateKeywordEntryState(form) {
    const input = form.querySelector(".keyword-input");
    if (!input) return;
    const values = selectedValues(form, input);
    input.placeholder = `Keyword ${Math.min(values.length + 1, MAX_KEYWORDS)}`;
    input.disabled = values.length >= MAX_KEYWORDS;
    const wrap = keywordEntryWrap(form);
    if (wrap) {
      wrap.hidden = values.length >= MAX_KEYWORDS;
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
    chip.className = "keyword-chip";
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
    chipList.appendChild(chip);
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
    form.addEventListener("click", (event) => {
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
      if (input.value.trim() || selectedValues(form, input).length) {
        fetchSuggestions(input);
      } else {
        const list = input.parentElement.querySelector(".keyword-suggestion-list");
        if (list) list.hidden = true;
      }
    });
  });

  document.querySelectorAll(".keyword-input").forEach((input) => {
    let timer = null;
    const showSuggestions = () => {
      const form = input.closest("[data-keyword-form]");
      if (isFirstKeywordInput(input) && !input.value.trim() && !selectedValues(form, input).length) {
        const list = input.parentElement.querySelector(".keyword-suggestion-list");
        if (list) list.hidden = true;
        return;
      }
      fetchSuggestions(input);
    };
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(() => fetchSuggestions(input), 90);
    });
    input.addEventListener("focus", showSuggestions);
    input.addEventListener("click", showSuggestions);
    input.addEventListener("keydown", (event) => {
      if (event.key === "Tab") {
        const list = input.parentElement.querySelector(".keyword-suggestion-list");
        if (list) list.hidden = true;
      }
    });
  });

  document.addEventListener("click", (event) => {
    if (!event.target.closest(".keyword-input-wrap")) {
      document.querySelectorAll(".keyword-suggestion-list").forEach((list) => {
        list.hidden = true;
      });
    }
  });

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
