# 029 — Incident: `public_html` / `ioms/public` Split-Directory 404s

## Status

Accepted. Documents a live production incident and its resolution, referenced from
`docs/ADR/027-deployment-architecture-redesign.md`.

## Summary

After the RC1 deployment architecture redesign (ADR-027) and the Node.js removal (ADR-028) were
both live, `https://ioms.web.id` still served 404s for every hashed CSS/JS asset
(`GET /build/assets/app-DlrD8SpM.css → 404`), even though `deploy.sh` completed successfully and
`public/build/manifest.json` was confirmed correct and up to date in the git repository.

## Investigation

This was audited end-to-end before any server change was made, specifically to rule out two
plausible-but-wrong explanations first:

1. **"Is `public/.htaccess` corrupted (duplicated rewrite block, stray formatting)?"** — Verified
   with a raw byte-level dump (`od -c`) of the tracked file: exactly one clean
   `<IfModule mod_rewrite.c>` block, 603 bytes, zero backtick characters, no duplication. The
   "triple backtick" appearance some terminal output showed was paste/rendering formatting, not
   file content. **Not the cause. No repository fix needed here.**
2. **"Is `public/build` out of sync with `manifest.json` in the repository?"** — `git ls-files
   public/build` returns exactly the 3 files the manifest references, nothing stale, nothing
   extra. **Not the cause either.**

The actual cause, confirmed directly from the live server's filesystem (not assumed):

- `~/public_html` (cPanel's fixed, domain-served web root) is a **real, separate directory** — not
  a symlink — last populated by a manual "copy the build output over" step from an earlier, less
  disciplined deploy process, dated several days stale.
- `~/ioms/public` (the actual Laravel project's `public/` folder, kept current by `git pull`) has
  the correct, current build.
- Apache serves `~/public_html`. Every `git pull` on `~/ioms` updated the *wrong* directory as far
  as what the browser actually receives.
- `~/public_html/.htaccess` itself also contained an old, separate Laravel rewrite block — a relic
  of `public_html` at some point having been its own independent Laravel public directory, not
  evidence of anything currently needed. It documents history, not a requirement to preserve.

This is a **Server/environment issue, not a Repository issue** — nothing in the git repository was
ever wrong. `public/`, `public/build`, `public/.htaccess`, and `manifest.json` were all correct the
entire time.

## Resolution

Per ADR-027's decision (preference order: change the domain's cPanel Document Root to
`~/ioms/public` directly; if the hosting plan doesn't allow that, symlink `public_html` to
`ioms/public`, **renaming** the old directory out of the way rather than deleting it):

```bash
mv ~/public_html ~/public_html.bak-$(date +%Y%m%d)
ln -s ~/ioms/public ~/public_html
```

This is a **one-time, per-environment action**, not a repeating deploy step — documented in
`README.md` § Deployment and in ADR-027. After it's applied, `~/public_html` and `~/ioms/public`
are the same inode; there is no second copy left to go stale on any future deploy.

The old `~/public_html.bak-<date>` directory is left in place (not deleted) until the symlink has
been confirmed working in production over normal use — consistent with the explicit instruction
that repository/server tooling must never default to irreversible deletion when a safe rename
achieves the same goal.

## Consequences

- Confirms ADR-027's root-cause analysis was correct on paper, and this ADR is the actual live
  verification record for it (ADR-027 pointed here for "the full incident" before this document
  existed).
- No code or migration change was needed to fix this — it is purely a one-time cPanel/filesystem
  action, which is exactly why ADR-027 classified it as a "one-time setup" step rather than
  something `deploy.sh`/`app:deploy` could or should handle automatically. A deploy script cannot
  safely repoint a domain's document root or blindly replace a directory it doesn't own; that stays
  a deliberate, human, one-time action.
- Reinforces the audit discipline used throughout this project: the two "plausible" hypotheses
  (corrupted `.htaccess`, stale `manifest.json`) were checked and ruled out with direct evidence
  before touching anything, rather than assumed and "fixed" pre-emptively.
