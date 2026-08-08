# 028 — Remove Node.js from the Production Deploy Flow

## Status

Accepted.

## Problem

`deploy.sh` (introduced in ADR-027) ran `npm ci && npm run build` on the production server. The
production shared host does not have Node.js installed at all -- `node`/`npm` both return "command
not found" -- so every deploy following that flow would fail at that step.

## Investigation

`public/build/` (Vite's compiled output, including `manifest.json`) was already committed to git,
not gitignored -- confirmed via `git ls-files public/build` and the explicit `# /public/build`
(commented-out, i.e. NOT ignored) line already present in `.gitignore`. This means `git pull` alone
already brings a correct, previously-built `public/build/` to the server; the `npm run build` step
in `deploy.sh` was redundant on top of that, not load-bearing -- it was rebuilding the exact same
output the repository already shipped, using a tool the server doesn't have.

## Decision

Frontend assets are built on a machine that has Node (a developer's machine, or CI later) and
**committed to git as part of the normal release process**, the same way a compiled binary would be
committed for a language that doesn't compile on the target. `deploy.sh` no longer references `npm`
at all -- the server-side flow is `git pull → composer install → php artisan app:deploy`, exactly
three steps, zero Node.js dependency anywhere in it.

`README.md` § 5 now states this explicitly up front, and adds a "before every deploy that touches
frontend code" step (`npm run build`, commit, push) as the one place Node.js is still involved in
the release process -- deliberately on the developer's own machine, never the server.

`app/Console/Commands/DeployCommand.php` (`app:deploy`) needed no change -- it never referenced
`npm`/`node` in the first place; it only orchestrates `php artisan` sub-commands.

## Ensuring the manifest and `public/build` stay synchronized

No new tooling was added for this (per the explicit "do not introduce new features" instruction) --
it's a process discipline point, not a technical guarantee:
- `npm run build` always empties and regenerates `public/build/` in one atomic run (Vite's default
  `emptyOutDir: true`), so there is never a mix of old and new hashed filenames sitting together;
  confirmed the currently-committed `public/build/` contains exactly the files `manifest.json`
  references, nothing orphaned.
- A backend-only change never touches `public/build/`, so there's nothing to rebuild or re-commit
  for it.
- A frontend change requires running `npm run build` and committing the result before pushing --
  documented plainly in README § 5. `npm run build` is idempotent/safe to re-run at any time (a
  no-op, nothing to commit, if the frontend hasn't changed since the last build), so "just run it
  again if unsure" is always a safe instruction.

## Verified

- Ran the entire server-side flow (`migrate:fresh --seed`, `php artisan app:deploy`) with `node` and
  `npm` completely absent from `PATH` -- both `which node` and `which npm` failed, confirming the
  simulation actually excluded them, not just skipped calling them. Every step succeeded.
- Started the app under that same Node-less `PATH` and fetched `/login` -- `200 OK`, with the
  rendered HTML referencing the exact committed, hashed asset filenames from `manifest.json`
  (`app-DlrD8SpM.css`, `app-DrB4nxMy.js`) -- confirming Laravel's `@vite` directive itself has no
  runtime dependency on Node either, only on the already-built `manifest.json` file being present.

## Consequences

- No CI/CD, no build-on-push automation, no pre-commit hook was added -- the "build locally, commit,
  push" step is manual, matching the explicit instruction not to introduce new infrastructure. If a
  developer forgets to rebuild after a frontend change, the server will keep serving the previous
  build (stale UI, not a 404 -- the committed `manifest.json` and `public/build/` are always
  internally consistent with *each other*, just possibly behind the latest `resources/js` source)
  until someone does rebuild and push. This is a known, accepted tradeoff for now, not a defect.
- `deploy.sh`'s one-time setup steps from ADR-027 (`public_html` symlink, `.env`, `COMPOSER_BIN`,
  cron) are unchanged -- this ADR only removes the `npm` step from the repeating per-deploy flow.
