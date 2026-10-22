import { config } from "./shared.js";

export function setupStatus() {
  const statusButton = document.querySelector("[data-ebd-status-refresh]");
  const statusMessage = document.querySelector("[data-ebd-status-message]");
  if (statusButton && statusMessage && config.statusUrl) {
    statusButton.addEventListener("click", async () => {
      statusButton.disabled = true;
      statusMessage.textContent = "Checking status...";
      try {
        const response = await fetch(config.statusUrl);
        const status = await response.json();
        statusMessage.textContent = status.database_connected
          ? `WordPress is connected to MySQL. Import state: ${status.import_state}.`
          : "The status endpoint could not be reached.";
      } catch (_error) {
        statusMessage.textContent = "The status endpoint could not be reached.";
      } finally {
        statusButton.disabled = false;
      }
    });
  }
}
