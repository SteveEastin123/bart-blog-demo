(function () {
  function selectedValues(form, exceptInput) {
    return Array.from(form.querySelectorAll(".keyword-input"))
      .filter((input) => input !== exceptInput)
      .map((input) => input.value.trim())
      .filter(Boolean);
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
        const inputs = Array.from(form.querySelectorAll(".keyword-input"));
        const next = inputs[inputs.indexOf(input) + 1];
        if (next) next.focus();
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
    input.addEventListener("focus", () => fetchSuggestions(input));
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
    checkbox.addEventListener("change", () => {
      const show = checkbox.checked;
      document.querySelectorAll(".post-description").forEach((description) => {
        description.hidden = !show;
      });
      document.querySelectorAll(".post-title").forEach((title) => {
        if (show) {
          title.removeAttribute("data-tooltip");
        } else {
          title.setAttribute("data-tooltip", title.getAttribute("data-description") || "");
        }
      });
    });
  });

  document.querySelectorAll(".post-title").forEach((title) => {
    title.setAttribute("data-tooltip", title.getAttribute("data-description") || "");
  });
})();
