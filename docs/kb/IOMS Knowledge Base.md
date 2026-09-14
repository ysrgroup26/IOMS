---
title: IOMS Knowledge Base
type: moc
product-version: 2.69.0
product-stage: Beta
updated: 2026-09-14
tags: [kb/home]
---

# IOMS Knowledge Base

The long-term memory for **IOMS — Industrial Operations Platform**. Start here.

> [!important] The one rule that keeps this maintainable
> **This vault is a map and an additive layer. It is not a copy.**
> Where a repository document is already authoritative, these notes *link to it*
> and explain how to read it. They do not restate its content, because two
> copies of a fact become two different facts within a release or two.
> The additive layer is the knowledge that genuinely did not exist anywhere:
> status tracking, a decision index, a glossary, a unified release index, and
> an honest verification record. See [[Knowledge Base Architecture]].

---

## If you are a Claude Code session starting cold

Read in this order. Four notes are usually enough to act safely:

1. **[[Product Identity and Principles]]** — what this product is, what it is called, and the rules that constrain every change.
2. **[[Current State]]** — the version, what exists, what is in flight, what is deliberately not being done.
3. **[[Requirements Register]]** — the tracked work items and their status.
4. **[[Working with This Knowledge Base]]** — how to record what you did when you are finished.

Then go deep only where your task touches:
[[Architecture Map]] · [[Data Ownership and Boundaries]] · [[Modules and Capabilities]] ·
[[Operational Workflows]] · [[Security Decisions and Lessons]] · [[UX and Design Principles]]

> [!warning] Before you build anything
> This codebase's most expensive mistakes were all *building something that
> already existed* or *changing something that was deliberate*. Check
> [[Decision Register]] and [[Modules and Capabilities]] first — and read
> `CONVENTIONS.md`'s pitfalls, which exist because each one actually happened.

---

## The map

### Product

| Note | Answers |
|---|---|
| [[Product Identity and Principles]] | What is IOMS, what may it be called, what rules constrain change |
| [[Product Strategy and Positioning]] | Who buys it, what is sold, how the departments map to plans |
| [[Domain Glossary]] | What a word means here — Tenant vs Company vs Operating Unit, PTW, SP, HIRADC, BAST |
| [[Pricing Plans and Entitlements]] | The four tiers and how entitlement is actually enforced |

### The product as built

| Note | Answers |
|---|---|
| [[Current State]] | Version, scale, what is implemented right now |
| [[Modules and Capabilities]] | Every module, its home in `MODULES.md`, and its status |
| [[Operational Workflows]] | How work actually moves — approvals, lifecycles, the procurement chain |
| [[Architecture Map]] | The reusable engines and where each one lives |
| [[Data Ownership and Boundaries]] | Tenancy, company ownership, roles, and what enforces them |

### Judgement and history

| Note | Answers |
|---|---|
| [[Decision Register]] | Every ADR, its status, and what supersedes or extends what |
| [[Security Decisions and Lessons]] | The isolation model, and the incidents that shaped it |
| [[UX and Design Principles]] | The design language, the form system, the language hierarchy |
| [[Release History]] | Every release, and which source is authoritative for which era |

### Work

| Note | Answers |
|---|---|
| [[Requirements Register]] | Planned / in progress / implemented / verified / deferred / superseded |
| [[Known Issues and Limitations]] | What is currently wrong or bounded, stated plainly |
| [[Verification Status]] | What was actually run, versus what was carefully reasoned |
| [[Working with This Knowledge Base]] | The update loop, and the status vocabulary |

---

## Authoritative sources outside this vault

These stay where they are. The vault points at them; it does not absorb them.

| Source | Authoritative for | Vault note that maps it |
|---|---|---|
| [[CLAUDE]] | Repository orientation, the language hierarchy rule | [[Product Identity and Principles]] |
| [[ARCHITECTURE]] | Engines, tenancy model, authorization approach | [[Architecture Map]] |
| [[MODULES]] | Per-module behaviour and business rules | [[Modules and Capabilities]] |
| [[CONVENTIONS]] | House style, and every pitfall that has actually bitten | [[Security Decisions and Lessons]] |
| `docs/ADR/*.md` | The reasoning behind individual decisions | [[Decision Register]] |
| `config/ioms.php` → `version_history` | Release summaries from v1.6.0 onward | [[Release History]] |
| [[CHANGELOG]] | Release detail for v1.1.0 – v1.5.4 only — **stale after that** | [[Release History]] |
| [[ROADMAP]] | Product direction and guiding principles | [[Requirements Register]] |
| [[UX_ARCHITECTURE_DISCOVERY]] | Navigation and data-entry UX reasoning | [[UX and Design Principles]] |
| [[LOCAL-VERIFICATION]] | How to actually run and verify the app | [[Verification Status]] |
| [[README]] | Install steps and the database ERD — **partly stale**, see [[Known Issues and Limitations]] |  |

---

## Conventions used in this vault

- **Status is a tag**, so Obsidian search and the tag pane can filter it:
  `#status/planned` `#status/in-progress` `#status/implemented` `#status/verified`
  `#status/deferred` `#status/superseded`. Defined in [[Working with This Knowledge Base]].
- **Implemented is not verified.** This project separates the two deliberately and so does this
  vault — see [[Verification Status]].
- **Superseded content is never deleted**, only marked. A decision that was reversed is more useful
  than a decision that quietly vanished.
