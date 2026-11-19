# Search Organization: Topics

Administrators can open **Tools > Search Organization > Topics** to create topics
and manage their category links. This is part of the production plugin, not the
demo-only reviewer tools.

## Supported operations

- Open to topic search without displaying the full topic list. Typing shows up to
  12 alphabetical matches with their current categories; selecting one opens its
  category-link editor. Matching uses the current directory already loaded on
  this administrator-only page, without a request for every keystroke.
- Use **Browse All Topics** for the alphabetical list, 25 records at a time, or
  **View All Matches** to see paginated search results. The search form also works
  without JavaScript. **Add Topic** remains visible beside the search controls.
- Create a topic with a unique name, a required description, and one or more
  existing categories. Names cannot duplicate a topic or its search alias.
- Add or remove category links on an existing topic. Multiple categories are
  supported, but every editable topic must retain at least one.
- Existing topic names, descriptions, slugs, and post assignments are unchanged.
  Internal/non-browsable topics, including Ignore, are protected.

Changes are saved together in a database transaction. The category editor checks
the topic snapshot before saving, so an older browser tab cannot overwrite a
newer edit. All writes require administrator permissions and a WordPress nonce.
The category picker supports filtering, scrolling, keyboard selection, and
removing selected items. A native multiple select remains available without JS.

Creating a topic makes it available to future metadata generation and to the
existing metadata topic picker. It does not assign the topic to old posts or
generate vectors. Existing pending metadata proposals continue to use the
vocabulary-change safety check; adding a topic can require regeneration before
approval. Category-link changes alone do not change that vocabulary fingerprint.

## Import limitation

These changes are stored only in the database. JSON export/synchronization is
intentionally outside this first release. A full replacement JSON import can
discard new topics and relationship edits. Before a full rebuild or handoff,
explicitly decide whether to incorporate changes into the canonical dataset or
discard them as test data. A Render edit does not automatically update local
JSON, GitHub, or the handoff data package.

## Verification

`wordpress-production/tests/topic-organization-tests.php` runs with the plugin
regression suite and is included in the separate developer-test delivery.
`wordpress-production/tests/topic-organization-ui.cjs` exercises real forms on
an isolated local WordPress/MariaDB site. No OpenAI calls are needed.
