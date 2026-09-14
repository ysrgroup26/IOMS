# 032 — The Obsidian Knowledge Base: a map over the documentation, not a copy of it

## Status

Accepted (v2.69.0, documentation-only change — no application behaviour was modified).

## Problem

IOMS carries roughly 11,900 lines of genuinely good documentation across 38 files: `ARCHITECTURE.md`,
`MODULES.md`, `CONVENTIONS.md`, `UX_ARCHITECTURE_DISCOVERY.md`, 29 ADRs, `README.md`, `ROADMAP.md`
and `CHANGELOG.md`. It is unusually well maintained for a project this size.

It is also **unusable as long-term memory for a fresh session**, for four specific reasons:

1. **No entry point that answers "what state is this product in right now."** `CLAUDE.md` orients
   you toward the documents, but nothing says what is implemented, what is pending, what is
   deliberately deferred, or what was superseded.
2. **The release record is split and partly unreachable.** `CHANGELOG.md` stops at v2.0.0;
   **71 releases exist only inside a PHP array** in `config/ioms.php`. That array is the *better*
   source — it is what the in-app About dialog renders, so it stayed current — but nobody reads
   release history out of a config file.
3. **Some documents are stale in parts and current in others**, with nothing saying which parts.
   `README.md`'s deployment sections are current while its ERD predates tenancy, procurement and
   Employee Cases. `ROADMAP.md` lists as "near-term" several things that have shipped.
4. **Whole dimensions of knowledge exist nowhere**: a glossary, a decision index with supersession
   relationships, a status register, and an honest record of what has actually been *run* versus
   carefully reasoned about.

## Decision

### 1. The vault is a map and an additive layer, never a copy

Where a repository document is authoritative, the knowledge base **links to it and explains how to
read it**. It does not restate its content.

This is the load-bearing constraint. The alternative — importing the documentation into a vault —
was rejected because two copies of a fact become two *different* facts within a release or two, and
this project already has the scar to prove it: the language policy drifted for fifteen releases
precisely because a rule lived in one place and the code lived in another.

The vault therefore adds only what genuinely did not exist: status tracking, a decision index, a
glossary, a unified release index, an issues register, and a verification record.

### 2. The vault root is the repository root

`.obsidian/` sits at the repository root rather than inside `docs/kb/`.

This means **every existing document becomes a first-class, linkable note** — `[[ARCHITECTURE]]`,
`[[CONVENTIONS]]`, `[[031-employee-cases]]` all resolve, backlinks work across the whole corpus, and
the graph shows the real structure. A vault rooted at `docs/kb/` would have been a walled garden
that could only link *out* by relative path, which is exactly the "competing system" outcome to
avoid.

`.obsidian/app.json` pins the ignore filters (`node_modules/`, `vendor/`, `public/build/`,
`storage/`, `tests/`) and the link style, and is committed so the vault opens the same way for
everyone. Per-user UI state (`workspace.json`, `appearance.json`, hotkeys, graph layout) is
gitignored.

### 3. Status is a tag vocabulary, and `implemented` ≠ `verified`

Six states: `#status/planned`, `#status/in-progress`, `#status/implemented`, `#status/verified`,
`#status/deferred`, `#status/superseded`.

They are tags rather than frontmatter because the tracked units are **rows in registers**, not
notes. One note per requirement would have produced hundreds of near-empty files; tags let Obsidian
search and the tag pane filter across every register at once without that cost.

Separating `implemented` from `verified` is not pedantry in this codebase — `CLAUDE.md` records that
for most of the project's history nothing was confirmed by running the application, and that a
single browser session then found four defects months of review had missed.

### 4. Superseded knowledge is marked, never deleted

A decision that was reversed is more useful than a decision that quietly vanished, because it stops
the same argument happening twice.

### 5. Conflicts are resolved in favour of the code, and the resolution is recorded

Where `ROADMAP.md` and the implementation disagreed, the code won and
[[Requirements Register]] records which items were resolved and why. Where a document is partly
stale, [[Known Issues and Limitations]] names the specific sections to distrust rather than
condemning the whole file.

### 6. Derived content is generated, not transcribed

The release index headlines are extracted from `config/ioms.php` by a documented snippet rather than
copied by hand, so regenerating cannot lose anything and cannot drift.

## Consequences

- A cold session can reach working context from four notes: [[Product Identity and Principles]],
  [[Current State]], [[Requirements Register]], [[Working with This Knowledge Base]].
- 71 previously hard-to-reach releases are now indexed and navigable.
- Existing documents keep their authority and their audience; nothing was moved or rewritten.
- The vault has **19 notes**, deliberately. Registers are one note with many rows, not many notes
  with one row.
- Two documents gained a short pointer header (`CHANGELOG.md`, `ROADMAP.md`) so a reader arriving
  there directly learns what is stale — integration rather than a parallel system.
- There is a maintenance cost: the registers must be updated in the same session as the work.
  [[Working with This Knowledge Base]] states that loop explicitly, and the reason it is stated
  rather than assumed is that `CHANGELOG.md` fell 71 releases behind by assuming it.

## What this deliberately does not do

- **No migration of existing docs into the vault.** They are authoritative where they are.
- **No per-requirement notes.** Registers are tables.
- **No Obsidian plugin dependencies.** Only core plugins; the vault degrades to plain Markdown that
  reads fine on GitHub and in any editor.
- **No attempt to backfill `CHANGELOG.md`.** That is real work with its own risks and is tracked as
  `#status/planned` rather than improvised here.
