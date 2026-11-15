const BROWSE_SORT_KEY = "ebd.browseTopicSort";

function savedBrowseSort() {
  try {
    return window.sessionStorage.getItem(BROWSE_SORT_KEY) === "alphabetical" ? "alphabetical" : "posts";
  } catch (_error) {
    return "posts";
  }
}

export function setupBrowseSort(control) {
  const select = control.querySelector("[data-ebd-browse-sort]");
  const list = control.closest(".ebd-discovery")?.querySelector("[data-ebd-browse-list]");
  if (!select || !list) return;

  // The server supplies alphabetical positions even when displaying most posts first.
  const items = Array.from(list.children, (element, index) => ({
    element, index: Number(element.dataset.ebdAlphabeticalOrder ?? index), count: Number(element.dataset.ebdPostCount) || 0,
  }));
  const apply = (mode) => {
    select.value = mode;
    const sorted = [...items].sort((left, right) =>
      (mode === "posts" ? right.count - left.count : 0) || left.index - right.index);
    sorted.forEach(item => list.appendChild(item.element));
  };
  select.addEventListener("change", () => {
    const mode = select.value === "posts" ? "posts" : "alphabetical";
    apply(mode);
    try {
      window.sessionStorage.setItem(BROWSE_SORT_KEY, mode);
    } catch (_error) {
      // Sorting still works when browser storage is unavailable.
    }
  });
  apply(savedBrowseSort());
  control.hidden = false;
  window.addEventListener("pageshow", () => apply(savedBrowseSort()));
}
