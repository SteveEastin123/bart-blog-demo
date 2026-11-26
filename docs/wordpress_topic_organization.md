# Manage Topics: Subject Areas, Categories, and Topics

Administrators can open **Blog Search > Manage Topics > Topics** to create topics
and manage their category links. This is part of the production plugin, not the
demo-only reviewer tools.

Tabs are ordered **Subject Areas**, **Categories**, **Topics**, **Export**. Subject Areas is
the default when opening Manage Topics; older unscoped topic-edit links
remain supported. All newly generated links specify their tab explicitly.
The **Subject Areas** tab uses the same search-first layout and category picker.
The three editing tabs place **Search**, **Browse All**, and **Add** in that order. Selecting a
search result or directory entry opens its category links. The full directory is
optional; each tab preserves its own navigation through searches and saves.

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
- Existing topic names, slugs, and post assignments are unchanged. Descriptions
  can be changed separately through **Edit Description**.
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

## Subject Areas

- Search subject-area names, browse the alphabetical directory, or add a subject
  area with a unique name and required description. Category links are optional:
  create the subject area first and add existing categories later.
- Add or remove category links using the same controls as Topics. Removing a link
  never deletes the category, its topics, or its posts, even when removing the last
  link. A category can belong to more than one subject area; adding a link does
  not move it out of another area.
- Existing subject-area names and URLs remain read-only; **Edit Description**
  opens a separate description-only form.
- New subject areas belong to the current Browse Topics path and are immediately
  available to public browsing. An area without categories has zero categories,
  topics, and posts until links are added. No schema change, metadata regeneration,
  or new vectors are required. Saves use transactions and stale-edit protection, with
  a distinct nonce and record ID field to prevent cross-tab submissions.

## Categories

- Search category names, optionally browse the alphabetical directory, or create
  a category with a unique name and required description.
- The directory has separate Category, Subject Areas, and Topics columns. Subject
  Areas are listed in full; Topics initially show the first three names. Both
  lists are alphabetized and separated by semicolons. **Show all N topics** expands
  longer lists within that category; **Show fewer** collapses them. These controls
  work with a keyboard and without JavaScript. The category name opens its editor,
  with the post count beneath it. On phones, the columns stack with clear labels.
- Select at least one existing Topic. Subject Area links are optional when
  creating or editing a category and can be added later.
  Separate searchable pickers show both relationship sets, with removable chips,
  keyboard support, and native multiple-select fallbacks without JavaScript.
- Existing category names and URLs are read-only. **Edit Description** saves
  separately from **Save Relationships**.
- Links are many-to-many. Adding an item never moves it out of other categories;
  removing a link never deletes the Topic, Subject Area, or its post assignments.
- A category save requires at least one Topic, but permits removing its last
  Subject Area link. Unlinked categories remain in Manage Topics, with their
  Topics and post assignments intact; they do not appear under any Subject Area
  in Browse Topics or hierarchy exports until linked.
- Unlinking a Topic or Subject Area is permitted even if it has no other
  category links.
  Those records remain available for relinking. The Topics tab still requires
  at least one category when saving a topic; the Subject Areas tab permits none.
- Only current Browse Topics subject areas and browsable topics can be selected.
  Existing internal-topic links and links from other browse paths are preserved.
- Both sides save in one transaction. Revisions include both relationship sets,
  so changes made from the Topics or Subject Areas tab invalidate a stale category
  form. No schema changes, post metadata generation, or vector updates occur.

## Description guidance

The creation and description-edit forms include brief guidelines and a collapsed
example beside the description field. Sentence and word counts are suggestions,
not additional validation rules.

- Topics: define the scope and useful distinctions from related topics in roughly
  1-2 sentences (25-50 words). Use neutral, reusable wording, without post-specific
  details, dates, or counts. Topic descriptions are supplied to the ingestion AI
  to guide topic selection as well as being displayed in Browse Topics.
- Subject Areas: explain the broad scope of the subject area,
  rather than listing categories, in roughly one sentence (20-40 words). Use neutral
  wording without dates or counts. These descriptions support browsing; they are
  not supplied to the ingestion AI.
- Categories: describe what connects the selected topics and distinguish the
  category from neighboring categories, in one or two clear sentences.

The post-ingestion rules for descriptions and summaries do not apply to these
vocabulary definitions. No AI prompts or existing validation limits are changed.

## Editing existing descriptions

Select an existing record, choose **Edit Description**, and then **Save Description**
or **Cancel**. This works without JavaScript. Saves require administrator access,
a tab-specific nonce, and a plain-text description of 1-4000 characters. Names,
slugs, relationships, post assignments, and vectors cannot be changed by this action.
Description-only saves also work for records left without links by category editing.

Topic editors display three safeguards:

- Clarify the topic's existing meaning; create a new topic for a substantially
  different subject. This is writing guidance, not an automated semantic check.
- Topic descriptions guide future AI assignments. Existing posts are not retagged
  and their vectors are not regenerated.
- Changing a topic description changes the ingestion vocabulary fingerprint.
  Pending metadata based on the previous vocabulary requires regeneration before
  approval. Approved metadata remains unchanged. Subject-area and category
  descriptions do not affect the ingestion vocabulary.

`Organization_Description_Service` locks the record, checks a description revision,
and updates only its description in a transaction. Concurrent description edits
are rejected; the failed form keeps its text and original revision. Cancel and
reopen to review the latest version. Relationship-only changes do not invalidate
the description revision or get overwritten. Existing relationship forms retain
their broader revision check. These saves do not invalidate Ask AI caches or call AI.

## Deletion

**Blog Search > Settings and Status > Allow Deletion** provides Disabled
and Enabled radio buttons, defaulting to **Disabled**. Selecting either option
saves it immediately, with Saving/Saved feedback, just like Metadata Generation.
Both groups use the administrator-only WordPress settings REST endpoint; a failed
save restores the confirmed selection or explains that the save could not be verified.
Secondary Keywords are not included.

Each existing Subject Area, Category, or editable Topic has a **Delete** button
below its relationship form. It stays disabled with an explanation when the
setting is off or a safeguard blocks deletion. When allowed, it opens a separate
confirmation showing the name and affected links. **Permanently Delete** requires
a dedicated nonce, POST, explicit confirmation, and administrator permission.
Cancel and GET navigation cannot delete data. The flow works without JavaScript.

- Subject Areas: linked Categories do not need another Subject Area. Deletion
  removes only the selected Subject Area and its category links. Categories
  without another Subject Area remain in Manage Topics for relinking, with their
  Topics and post assignments intact; they no longer appear under a Subject Area
  in Browse Topics or hierarchy exports. Empty Subject Areas may also be deleted.
- Categories: every linked Topic (including internal topics) must have another
  Category. An empty Category may be deleted.
- Topics: no post may reference the Topic. Remove or replace post assignments
  first. Internal/non-browsable topics, including Ignore, cannot be deleted.
- Only the selected item and its relationship rows are removed. Topic aliases
  are removed with an unused Topic. No posts, child records, keywords, summaries,
  vectors, or ingestion history are deleted, and there is no bulk-delete action.

`Organization_Deletion_Service` rechecks the stored setting, record revision and
references inside a transaction. Vocabulary locks coordinate with existing
organization writers; approval holds shared locks on selected Topics and rejects
ones deleted since vocabulary validation. Database failures roll back the whole
deletion. Stale confirmations must be reopened. Successful deletions invalidate
search caches; Topic deletion changes the ingestion vocabulary fingerprint, so
pending metadata using the earlier vocabulary requires regeneration.

## Export

The fourth tab downloads the current database hierarchy, not the original JSON.
**Entire structure** is selected by default; **Selected Subject Areas** allows
one or several areas. PDF and CSV share the same alphabetical relationship rows.
Shared categories/topics repeat under each parent. No descriptions, posts,
keywords, vectors, or internal/Ignore topics are included. Empty subject areas and
categories remain visible; unlinked categories/topics are outside the subject-area
hierarchy and are not included.

CSV contains Subject Area, Category, and Topic columns, one row per relationship,
with UTF-8, standard CSV quoting, and formula-injection protection. PDF is a
paginated outline with subject-area headings, categories, and bulleted topics.
PDF generation runs locally in the browser using bundled pdfmake and Roboto assets;
there are no external requests or new PHP runtime dependencies. CSV also works
without JavaScript. PDF requires JavaScript.

Downloads use a dedicated `admin-post.php` action protected by `manage_options`,
a POST request, and a nonce. Unknown, stale, malformed, or other-path selections
are rejected. The export cannot change the database or import data.
These are review documents, not a replacement for the JSON/vector handoff package.

## Import limitation

Topic, subject-area, and category changes are stored only in the database. JSON export and
synchronization are intentionally outside this first release. A full replacement JSON import can
discard new records, description edits, and relationship edits, and restore deleted
records. Before a full rebuild or handoff,
explicitly decide whether to incorporate changes into the canonical dataset or
discard them as test data. A Render edit does not automatically update local
JSON, GitHub, or the handoff data package.

## Code Organization

`Topic_Organization_Page` retains the existing routes, permissions, form handling,
and view preparation. Its shared context uses `item` and `items` for all three
editing tabs. `Organization_Page_Labels` owns tab-specific labels and description
guidance. The `search-organization.php` template is the page shell; it delegates to
`organization-search.php`, `organization-directory.php`, `organization-form.php`,
and `organization-edit-description.php`. Existing description-field, relationship,
category-row, deletion, and export templates remain shared.

Single-record service reads query the selected ID and its links, retaining the
same fields, ordering, post counts, and revision hashes as directory entries.
Edit pages do not load the main directory. Relationship pickers use lightweight
ID/name queries, preserving topic protection and current browse-path scope;
description and deletion forms do not load unused picker choices.

Template extraction does not change submitted field names, nonces, HTML hooks,
URLs, relationship requirements, or database writes. Organization services retain
their existing validation, transaction, and stale-edit protections.

## Verification

`wordpress-production/tests/topic-organization-tests.php` runs with the plugin
regression suite, as do `subject-area-organization-tests.php`,
`category-organization-tests.php`, `organization-description-tests.php`, and
`organization-export-tests.php`, and `organization-deletion-tests.php`; all are included
in the separate developer-test delivery.
`wordpress-production/tests/organization-export-ui.cjs` verifies real downloads,
selection, permissions, error recovery, no-JavaScript CSV, and responsive layouts.
`wordpress-production/tests/topic-organization-ui.cjs` exercises all three editing tabs on
an isolated local WordPress/MariaDB site. No OpenAI calls are needed.
`wordpress-production/tests/organization-deletion-ui.cjs` checks the default-off
setting, disabled controls, confirmations, cancellation, nonce protection,
assigned-post protection, no-JavaScript behavior and mobile layout using disposable
fixtures only. Deletion tests also cover rollback and concurrent approval locks.
