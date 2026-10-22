import { setupStructureReview, directChild } from "./review-tools.js";
import { descriptionMode, showTooltip, hideTooltip } from "../../../../../wordpress-plugin/ehrman-blog-discovery/assets/src/js/descriptions.js";

document.querySelectorAll(".ebd-view-structure-review").forEach(root => {
  setupStructureReview(root);
  const updateDescriptions = () => {
    const mode = descriptionMode(root);
    root.querySelectorAll(".ebd-review-description").forEach(description => {
      description.hidden = mode !== "always";
    });
    if (mode !== "hover") hideTooltip();
  };
  root.querySelector("[data-ebd-description-mode]")?.addEventListener("change", updateDescriptions);
  updateDescriptions();
  root.querySelectorAll(".ebd-review-name").forEach(name => {
    const section = name.closest(".ebd-review-area,.ebd-review-category,.ebd-review-topic");
    const description = directChild(section, ".ebd-review-description");
    if (!description?.textContent.trim()) return;
    const anchor = name.querySelector("span:last-child") || name;
    name.dataset.ebdTooltipReady = "true";
    const show = () => {
      if (descriptionMode(root) === "hover") showTooltip(anchor, description.textContent.trim(), "right");
    };
    anchor.addEventListener("mouseenter", show);
    anchor.addEventListener("mouseleave", hideTooltip);
    anchor.addEventListener("focus", show);
    anchor.addEventListener("blur", hideTooltip);
  });
});
