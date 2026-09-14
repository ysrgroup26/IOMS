---
title: Working with This Knowledge Base
type: process
updated: 2026-09-14
tags: [kb/process]
---

# Working with This Knowledge Base

How to read it, how to update it, and what the status words mean.

---

## The status vocabulary

Six states. They are tags so Obsidian's search and tag pane can filter across every register at
once (`tag:#status/deferred`), and they mean exactly this:

| Tag | Meaning | Evidence required |
|---|---|---|
| `#status/planned` | Agreed as wanted. Nobody is working on it. | A reason it matters |
| `#status/in-progress` | Actively being built right now. | — |
| `#status/implemented` | The code exists and the suite passes. | Version + commit |
| `#status/verified` | Additionally exercised in a **running application**, or pinned by a test that would fail if it regressed. | Version + commit + how it was verified |
| `#status/deferred` | Deliberately not being done, with a reason. Not the same as forgotten. | The reason, and what would change the answer |
| `#status/superseded` | Was true, has been replaced. Kept so nobody re-litigates it. | What replaced it |

> [!important] `implemented` and `verified` are different states on purpose
> This project has been burned by the distinction. `CLAUDE.md` says it plainly: for most of the
> project's history *nothing* was confirmed by running the application, and a single 30-minute
> browser session then found four defects that months of code review had missed. Marking something
> `#status/verified` is a claim that somebody ran it. Do not promote a row without that.
> See [[Verification Status]].

---

## The update loop

When you finish a piece of work, update the knowledge in the **same session**. A separate cleanup
pass does not happen — `CLAUDE.md` says so, and the evidence is `CHANGELOG.md`, which fell 71
releases behind precisely that way.

1. **Read** [[Current State]] and [[Requirements Register]] before starting.
2. **Implement** the change.
3. **Move the row** in [[Requirements Register]] to its new status, and fill the evidence columns
   (version, commit, how it was verified).
4. **Update the notes your change invalidated.** Use the table below — it maps the kind of change
   to the notes that go stale.
5. **If you made a significant, non-obvious decision**, write an ADR in `docs/ADR/` and add a row
   to [[Decision Register]]. The ADR holds the reasoning; the register holds only its status and
   relationships.
6. **If you found a defect or a limit you are not fixing**, add it to
   [[Known Issues and Limitations]] rather than leaving it in a commit message.
7. **Record the release** — bump `config/ioms.php` and add the row to [[Release History]].

### What goes stale when

| If you changed… | Update |
|---|---|
| A module's behaviour or business rules | `MODULES.md` (authoritative), then [[Modules and Capabilities]] if its status changed |
| A reusable engine, or added one | `ARCHITECTURE.md` (authoritative), then [[Architecture Map]] |
| A lifecycle, state machine or approval path | [[Operational Workflows]] |
| Scoping, roles, or anything authorization | `ARCHITECTURE.md`, then [[Data Ownership and Boundaries]] and [[Security Decisions and Lessons]] |
| A term, or introduced a new one | [[Domain Glossary]] |
| Pricing, plans, entitlement | [[Pricing Plans and Entitlements]] |
| A UX rule, shared component or design convention | `CONVENTIONS.md` (authoritative), then [[UX and Design Principles]] |
| Anything a future reader would ask "why?" about | A new ADR + [[Decision Register]] |
| Something that had been true and no longer is | Mark it `#status/superseded` — **do not delete it** |

---

## Writing rules for this vault

- **Link, do not copy.** If the fact lives in `MODULES.md`, link to `MODULES.md`. A vault note that
  restates a module's field list is a second source of truth that will drift.
- **State the source when a fact is load-bearing.** "353 tests" is only useful if the reader knows
  it came from `vendor/bin/phpunit` on a stated date.
- **Do not invent to fill a heading.** An empty section is honest; a plausible-sounding invented one
  is a trap. If a dimension genuinely has no content yet, say so and say why.
- **Mark rather than delete.** Superseded decisions are the cheapest way to stop the same argument
  happening twice.
- **Keep note count low.** Notes are merged by the question they answer, not split by taxonomy.
  A register is one note with many rows, not many notes with one row.

---

## Regenerating the release index

[[Release History]] is derived from the authoritative sources rather than hand-maintained. To
rebuild its table after a release, run this from the repository root:

```bash
py -3 -c "
import re, io
cfg = io.open('config/ioms.php', encoding='utf-8', errors='replace').read()
rows = re.findall(r\"\['version' => '([\d.]+)', 'date' => '([\d-]+)', 'summary' => '(.*?)'\],\", cfg, re.S)
def headline(s):
    m = re.match(r'^([A-Z0-9][A-Z0-9 ,\'/&()+.-]{6,90}?)(?: -- |\. |, (?=[A-Z][a-z]))', s.strip())
    if m: return m.group(1).strip().rstrip('.,')
    m2 = re.match(r'^(.{10,90}?)(?:\. |\s--\s)', s.strip())
    return (m2.group(1) if m2 else s[:90]).strip().rstrip('.,')
key = lambda v: tuple(int(x) for x in v.split('.'))
for v, d, s in sorted(rows, key=lambda r: key(r[0]), reverse=True):
    print(f'| \`{v}\` | {d} | {headline(s)} |')
"
```

The headline is *derived*, not authored — the full summary stays in `config/ioms.php`, which is what
the in-app About dialog renders. Regenerating cannot lose anything.

---

See also: [[IOMS Knowledge Base]] · [[Knowledge Base Architecture]]
