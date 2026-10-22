import { setupForm, closeSuggestions } from "./keyword-search.js";
import { setupSemanticForm, setupResultViews } from "./ask-ai.js";
import { setupFeedback } from "./feedback.js";
import { setupDescriptionMode, setupTitleTooltips } from "./descriptions.js";
import { semanticPageResult, renderResults } from "./results.js";
import { updateBrowserUrl } from "./search-state.js";
import { setupStatus } from "./status.js";

setupResultViews();
setupFeedback();
document.querySelectorAll("[data-ebd-search-form]").forEach(setupForm);
document.querySelectorAll("[data-ebd-semantic-form]").forEach(setupSemanticForm);
document.querySelectorAll("[data-ebd-description-mode]").forEach(setupDescriptionMode);
setupTitleTooltips(document);

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
  if (event.target.closest("[data-ebd-back-to-top]")) {
    const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    window.scrollTo({ top: 0, left: 0, behavior: reducedMotion ? "auto" : "smooth" });
    return;
  }
  if (!event.target.closest(".ebd-keyword-input-wrap,.ebd-suggestion-order-row")) {
    document.querySelectorAll(".ebd-keyword-input").forEach(closeSuggestions);
  }
});

setupStatus();
