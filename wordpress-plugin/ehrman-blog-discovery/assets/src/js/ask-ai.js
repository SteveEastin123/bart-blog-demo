import { config } from "./shared.js";
import { renderResults, sortedRefinedResult, semanticPageResult } from "./results.js";
import { updateBrowserUrl } from "./search-state.js";


export function setupSemanticForm(form) {
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

export function setupResultViews() {
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-ebd-show-broader],[data-ebd-show-refined]");
    if (!button) return;
    const discovery = button.closest(".ebd-discovery");
    const form = discovery?.querySelector("[data-ebd-semantic-form]");
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
  });
}
