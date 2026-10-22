const DESCRIPTION_MODE_KEY = "ehrmanDiscovery.descriptionMode";
let tooltip = null;

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

export function showTooltip(element, text, placement = "auto", horizontalElement = element) {
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

export function hideTooltip() {
  if (!tooltip) return;
  tooltip.hidden = true;
  tooltip.textContent = "";
}

export function setupTitleTooltips(root) {
  root.querySelectorAll(".ebd-item-title,.ebd-post-title").forEach((title) => {
    if (title.dataset.ebdTooltipReady) return;
    title.dataset.ebdTooltipReady = "true";
    const isNavigation = title.classList.contains("ebd-navigation-link");
    const anchor = isNavigation
      ? (title.querySelector(".ebd-navigation-name > span:last-child") || title)
      : title;
    const hoverTarget = anchor;
    hoverTarget.addEventListener("mouseenter", () => {
      const pageRoot = title.closest(".ebd-discovery") || document;
      if (descriptionMode(pageRoot) === "hover") {
        showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
      }
    });
    hoverTarget.addEventListener("mouseleave", hideTooltip);
    title.addEventListener("focus", () => {
      const pageRoot = title.closest(".ebd-discovery") || document;
      if (descriptionMode(pageRoot) === "hover") {
        showTooltip(anchor, title.dataset.description || "", isNavigation ? "right" : "auto");
      }
    });
    title.addEventListener("blur", hideTooltip);
  });
}

export function descriptionMode(root) {
  return root.querySelector("[data-ebd-description-mode] input:checked")?.value || "hidden";
}

function descriptionPreferenceScope(control) {
  return control.dataset.scope === "posts" ? "posts" : "browse";
}

function savedDescriptionMode(control) {
  try {
    return window.sessionStorage.getItem(`${DESCRIPTION_MODE_KEY}.${descriptionPreferenceScope(control)}`) || "";
  } catch (_error) {
    return "";
  }
}

function rememberDescriptionMode(control, mode) {
  try {
    window.sessionStorage.setItem(`${DESCRIPTION_MODE_KEY}.${descriptionPreferenceScope(control)}`, mode);
  } catch (_error) {
    // The display control remains usable when browser storage is unavailable.
  }
}

export function applyDescriptionMode(root) {
  const mode = descriptionMode(root);
  root.querySelectorAll(".ebd-item-description,.ebd-post-description").forEach((description) => {
    description.hidden = mode !== "always";
  });
  if (mode !== "hover") hideTooltip();
}

export function setupDescriptionMode(control) {
  const root = control.closest(".ebd-discovery") || document;
  const preferred = savedDescriptionMode(control);
  let mode = ["always", "hover", "hidden"].includes(preferred)
    ? preferred
    : (control.dataset.defaultMode || "hover");
  if (mode === "hover" && window.matchMedia("(hover: none) and (pointer: coarse)").matches) {
    mode = "hidden";
  }
  const selected = control.querySelector(`input[value="${mode}"]`);
  if (selected) selected.checked = true;
  control.addEventListener("change", (event) => {
    if (!event.target.matches('input[type="radio"]')) return;
    rememberDescriptionMode(control, event.target.value);
    applyDescriptionMode(root);
  });
  applyDescriptionMode(root);
}
