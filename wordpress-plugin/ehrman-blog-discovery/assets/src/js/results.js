import { strings, normalized, postWord } from "./shared.js";
import { browserUrl, updateBrowserUrl } from "./search-state.js";
import { applyDescriptionMode, setupTitleTooltips } from "./descriptions.js";
import { feedbackControl } from "./feedback.js";
const BACK_TO_TOP_THRESHOLD = 10;

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

export function renderResults(container, result, form) {
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
  const grouped = result.refined
    && (result.sort || "ranked") === "ranked"
    && renderRelevanceGroups(container, result.posts, context);
  if (!grouped) container.append(postList(result.posts, context));
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

function postList(posts, context) {
  const list = document.createElement("ul");
  list.className = "ebd-post-list";
  posts.forEach((post) => {
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
  return list;
}

function renderRelevanceGroups(container, posts, context) {
  const definitions = [
    ["direct", strings.directAnswers || "Directly answers the question"],
    ["related", strings.stronglyRelated || "Strongly related"],
    ["background", strings.supporting || "Supporting background"],
  ];
  const validTiers = new Set(definitions.map(([tier]) => tier));
  if (!posts.length || !posts.every((post) => validTiers.has(post.relevance_tier))) return false;
  definitions.forEach(([tier, label]) => {
    const matches = posts.filter((post) => post.relevance_tier === tier);
    if (!matches.length) return;
    const section = document.createElement("section");
    const heading = document.createElement("h3");
    const count = document.createElement("span");
    section.className = `ebd-relevance-group is-${tier}`;
    heading.className = "ebd-relevance-heading";
    heading.append(document.createTextNode(label));
    count.className = "ebd-relevance-count";
    count.textContent = `${matches.length} ${matches.length === 1 ? "post" : "posts"}`;
    heading.append(count);
    section.append(heading, postList(matches, context));
    container.append(section);
  });
  return true;
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

export function sortedRefinedResult(result, sort) {
  if (!result || sort === "ranked") return result;
  const posts = [...(result.posts || [])].sort((left, right) => {
    const comparison = String(left.published_at || "").localeCompare(String(right.published_at || ""));
    return sort === "oldest" ? comparison : -comparison;
  });
  return { ...result, posts, sort };
}

export function semanticPageResult(result, page = 1, sort = "ranked") {
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
