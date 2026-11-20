# Topics & Categories: Subject Areas, Categories, and Topics

Administrators can open **Blog Search > Topics & Categories > Topics** to create topics
and manage their category links. This is part of the production plugin, not the
demo-only reviewer tools.

Tabs are ordered **Subject Areas**, **Categories**, **Topics**. Subject Areas is
the default when opening Topics & Categories; older unscoped topic-edit links
remain supported. All newly generated links specify their tab explicitly.
The **Subject Areas** tab uses the same search-first layout and category picker.
All tabs place **Search**, **Browse All**, and **Add** in that order. Selecting a
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
  area with a unique name, required description, and at least one existing category.
- Add or remove category links using the same controls as Topics. Removing a link
  never deletes the category, its topics, or its posts. A category can belong to
  more than one subject area; adding a link does not move it out of another area.
- Existing subject-area names and URLs remain read-only; **Edit Description**
  opens a separate description-only form.
- New subject areas belong to the current Browse Topics path and are immediately
  available to public browsing. No schema change, metadata regeneration, or new
  vectors are required. Saves use transactions and stale-edit protection, with
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
- Select at least one existing Subject Area and at least one existing Topic.
  Separate searchable pickers show both relationship sets, with removable chips,
  keyboard support, and native multiple-select fallbacks without JavaScript.
- Existing category names and URLs are read-only. **Edit Description** saves
  separately from **Save Relationships**.
- Links are many-to-many. Adding an item never moves it out of other categories;
  removing a link never deletes the Topic, Subject Area, or its post assignments.
- A category save requires one selection on each side, but deliberately permits
  unlinking a Topic or Subject Area even if it has no other category links.
  Those records remain available for relinking. The other tabs still require
  at least one category when saving their own records.
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
- Subject Areas: explain the broad connection between the selected categories,
  rather than listing them, in roughly one sentence (20-40 words). Use neutral
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

## Import limitation

Topic, subject-area, and category changes are stored only in the database. JSON export and
synchronization are intentionally outside this first release. A full replacement JSON import can
discard new records, description edits, and relationship edits. Before a full rebuild or handoff,
explicitly decide whether to incorporate changes into the canonical dataset or
discard them as test data. A Render edit does not automatically update local
JSON, GitHub, or the handoff data package.

## Verification

`wordpress-production/tests/topic-organization-tests.php` runs with the plugin
regression suite, as do `subject-area-organization-tests.php` and
`category-organization-tests.php` and `organization-description-tests.php`; all are included
in the separate developer-test delivery.
`wordpress-production/tests/topic-organization-ui.cjs` exercises all three tabs on
an isolated local WordPress/MariaDB site. No OpenAI calls are needed.
