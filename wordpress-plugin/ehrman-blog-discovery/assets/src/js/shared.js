export const config = window.EhrmanDiscovery || {};
export const strings = config.strings || {};

export function normalized(value) {
  return String(value || "")
    .trim()
    .toLocaleLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9]+/g, " ")
    .trim()
    .replace(/\s+/g, " ");
}

export function postWord(count) {
  return count === 1 ? (strings.post || "post") : (strings.posts || "posts");
}
