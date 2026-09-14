---
title: Knowledge Base Architecture
type: reference
updated: 2026-09-14
tags: [kb/meta]
---

# Knowledge Base Architecture

How this vault is laid out and why. **The decision and its reasoning are in ADR
[[032-obsidian-knowledge-base|032]]** — this note is the practical layout.

---

## Layout

```
<repository root>          <- the Obsidian vault root
├── .obsidian/             <- committed: app.json (ignore filters, link style), core-plugins.json
├── CLAUDE.md              <- repository orientation (authoritative)
├── README.md  ROADMAP.md  CHANGELOG.md
└── docs/
    ├── ARCHITECTURE.md  MODULES.md  CONVENTIONS.md
    ├── UX_ARCHITECTURE_DISCOVERY.md  LOCAL-VERIFICATION.md
    ├── ADR/               <- 30 decision records
    └── kb/                <- this knowledge base (19 notes)
```

**The vault root is the repository root**, so every existing document is a linkable note.
`[[ARCHITECTURE]]`, `[[CONVENTIONS]]` and `[[031-employee-cases]]` all resolve, and backlinks work
across the whole corpus. A vault rooted at `docs/kb/` would have been a walled garden.

Opening it: point Obsidian at the repository folder. The committed `app.json` excludes
`node_modules/`, `vendor/`, `public/build/`, `storage/`, `bootstrap/cache/`, `.claude/` and `tests/`
so the graph shows documentation rather than dependencies.

## The two layers

| Layer | Where | Rule |
|---|---|---|
| **Authoritative documentation** | `CLAUDE.md`, `docs/*.md`, `docs/ADR/`, `config/ioms.php` | Unchanged. Keeps its authority and its audience |
| **The knowledge base** | `docs/kb/` | Maps the first layer and adds what did not exist |

> [!important] The rule that keeps it maintainable
> **Link, do not copy.** A vault note that restates a module's field list is a second source of
> truth that will drift. If the fact lives in `MODULES.md`, link to `MODULES.md`.

## What the vault adds

Everything here genuinely did not exist anywhere before:

| Addition | Note |
|---|---|
| A statused work register | [[Requirements Register]] |
| A decision index with supersession and extension relationships | [[Decision Register]] |
| A terminology reference, including the pairs that cause bugs | [[Domain Glossary]] |
| A unified release index across two incomplete sources | [[Release History]] |
| An honest record of what was run versus reasoned | [[Verification Status]] |
| A register of what is stale, bounded or broken | [[Known Issues and Limitations]] |
| A current-state snapshot with the commands to re-measure it | [[Current State]] |

## Note types

`type:` in the frontmatter, used for orientation rather than automation:

- **`moc`** — the map of content ([[IOMS Knowledge Base]])
- **`reference`** — durable explanation that changes slowly
- **`index`** — points outward at authoritative sources
- **`register`** — statused rows that change with the work
- **`snapshot`** — true as measured, with a date
- **`process`** — how to work with the vault itself

## Why 19 notes and not 200

Notes are merged by **the question they answer**, not split by taxonomy. A register is one note with
many rows, not many notes with one row. Hundreds of tiny notes look comprehensive and cost more to
navigate and maintain than the corpus they describe.

## Integration with existing documents

Two files gained a short pointer header so a reader arriving there directly learns what is stale:
`CHANGELOG.md` (stops at v2.0.0) and `ROADMAP.md` (near-term sections partly shipped). Nothing else
was moved, rewritten or deleted.

`CLAUDE.md` points here as the entry point for product state and history.

---

See also: [[IOMS Knowledge Base]] · [[Working with This Knowledge Base]] · [[032-obsidian-knowledge-base|ADR 032]]
