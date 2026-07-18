(function () {
  function selectedValues(form, exceptInput) {
    return Array.from(form.querySelectorAll(".keyword-input"))
      .filter((input) => input !== exceptInput)
      .map((input) => input.value.trim())
      .filter(Boolean);
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
    if (isFirstKeywordInput(input) && !input.value.trim()) {
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
        input.value = suggestion.label;
        list.hidden = true;
        input.focus({ preventScroll: true });
      });
      item.appendChild(button);
      list.appendChild(item);
    });
    list.hidden = false;
  }

  document.querySelectorAll(".keyword-input").forEach((input) => {
    let timer = null;
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(() => fetchSuggestions(input), 90);
    });
    input.addEventListener("focus", () => {
      if (isFirstKeywordInput(input)) {
        const list = input.parentElement.querySelector(".keyword-suggestion-list");
        if (list) list.hidden = true;
        return;
      }
      fetchSuggestions(input);
    });
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

  document.querySelectorAll("[data-description-toggle]").forEach((checkbox) => {
    const contentPage = checkbox.closest(".content-page") || document;
    const descriptions = contentPage.querySelectorAll(".post-description, .item-description, .content-description");
    const describedTitles = contentPage.querySelectorAll(".post-title, .item-title, .described-heading");
    checkbox.addEventListener("change", () => {
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
    });
  });

  document.querySelectorAll(".post-title, .item-title, .described-heading").forEach((title) => {
    title.setAttribute("data-tooltip", title.getAttribute("data-description") || "");
  });
})();
