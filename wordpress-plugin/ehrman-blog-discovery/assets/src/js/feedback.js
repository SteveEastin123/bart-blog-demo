import { config, strings } from "./shared.js";


export function feedbackControl(requestId) {
  const section = document.createElement("section");
  const prompt = document.createElement("span");
  const status = document.createElement("span");
  section.className = "ebd-ai-feedback";
  section.dataset.ebdAiFeedback = "true";
  section.dataset.requestId = requestId;
  prompt.textContent = "Were these search results helpful?";
  section.append(prompt);
  ["Yes", "No"].forEach((label) => {
    const button = document.createElement("button");
    button.type = "button";
    button.dataset.ebdFeedbackValue = label.toLowerCase();
    button.textContent = label;
    section.append(button);
  });
  status.className = "ebd-ai-feedback-status";
  status.dataset.ebdFeedbackStatus = "true";
  status.setAttribute("aria-live", "polite");
  section.append(status);
  return section;
}

export function setupFeedback() {
  document.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-ebd-feedback-value]");
    if (!button || !config.feedbackUrl) return;
    const section = button.closest("[data-ebd-ai-feedback]");
    const status = section?.querySelector("[data-ebd-feedback-status]");
    if (!section || !status || section.dataset.submitted === "true") return;
    const buttons = section.querySelectorAll("[data-ebd-feedback-value]");
    buttons.forEach((item) => { item.disabled = true; });
    try {
      const response = await fetch(config.feedbackUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          request_id: section.dataset.requestId || "",
          helpful: button.dataset.ebdFeedbackValue === "yes",
        }),
      });
      if (!response.ok) throw new Error("Feedback request failed");
      section.dataset.submitted = "true";
      button.classList.add("is-selected");
      status.textContent = strings.feedbackThanks || "Thank you. Your feedback will help improve the search.";
    } catch (error) {
      buttons.forEach((item) => { item.disabled = false; });
      status.textContent = strings.feedbackFailed || "The feedback could not be saved. Please try again.";
    }
  });
}
