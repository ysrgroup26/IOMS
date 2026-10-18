# ADR 057 — A release must be able to prove what is running

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.118.0
- **Supersedes:** nothing. Extends ADR 024 (stale config cache) and ADR 027/028 (deployment architecture).

## Context

PPE Master refused to let a customer change a built-in PPE type. That was a real
defect, it was diagnosed, and it was corrected: a built-in is no longer rewritten,
and an Operating Unit gets its own row that supersedes the template for that company
only (ADR 050). The correction was committed, covered by eleven tests in
`SharedMasterDataOwnershipTest` — including the owner's own example, Safety Shoes
from twelve months to six — and released.

The owner deployed it. PPE Master still answered:

> Jenis APD bawaan tidak dapat diubah. Tambahkan jenis APD milik organisasi Anda
> bila perlu yang berbeda.

A second corrective release was prepared and deployed. The behaviour did not change.

### What the investigation actually found

That sentence exists in **no file** of the release that was deployed. `git grep` over
the released commit returns nothing; it was last present in v2.106.0 and was deleted
by the first corrective release. Checking out the released commit in an isolated
environment, provisioning it against MySQL 8, and driving the same screen in a real
browser, the twelve-to-six edit **saves and persists**, with no refusal shown.

So the committed code was never the defect. The code that reached the server was
something else, and there were two concrete reasons available:

1. **The repository carried two divergent release lines.** `main` held the PPE and
   My Work corrections. A development branch held the Document Platform, the employee
   import repairs, permanent employee deletion and the HSE Overview. Neither line
   contained the other, and both had published a release numbered v2.108.0. `deploy.sh`
   runs a bare `git pull`, which updates **whichever branch the server is on** — so
   deploying "the latest release" could legitimately mean either line, and whichever
   one it meant, something the customer had been told was fixed was absent from the
   code that ran.
2. **Nothing in the deploy flow or the application could report which code was
   executing.** `public/build/` is committed, so a touched asset on the server makes
   `git pull` refuse while later deploy steps still print success. OPcache in the web
   workers can hold the previous release's compiled classes, and the deploy runs under
   the CLI, which has its **own** OPcache — so the deploy sees new code while every web
   request sees old code. Before this release, no file in this repository mentioned
   opcache at all.

In every one of those cases the deploy log reads clean and the application keeps
serving the previous release.

### Why no amount of testing would have caught it

Every test was passing, correctly, about code that was not answering requests. A test
asserts behaviour of the code the test loads. It cannot assert which code a different
process on a different machine has compiled. Three rounds of "tests pass, build clean,
released" were all true statements about the wrong subject, and the reports were
therefore not evidence of the thing the owner cared about.

## Decision

**A release must be able to state its own identity, from the process that is serving
requests, and a deploy must refuse to finish quietly when the database contradicts the
code.**

1. **The two release lines are merged into one.** The PPE override mechanism keeps the
   shape `main` shipped (`ppe_types.source_default_id`), because that is the mechanism
   proven in a browser, not because it was first. A parallel implementation developed on
   the other branch is discarded rather than reconciled: two mechanisms for one rule is
   how this situation arose.

2. **`ReleaseStatusService` reports the identity of the code executing right here** —
   version, stage, build, the commit and branch of the checkout, a content signature over
   release-critical files, and the OPcache configuration. It reads `.git` directly rather
   than shelling out, because shared hosting routinely has no `git` on the PHP process's
   PATH and `exec()` disabled: a shell call would report "unknown" on exactly the hosts
   this exists to diagnose.

3. **The operations console renders it from a WEB request** (Master Admin → Running
   Release). This is the whole point. The deploy's answer comes from the CLI; when the two
   disagree, the web process is the one telling the truth about what customers are served.
   The console prints the signature next to the deploy's so an operator compares two
   strings instead of guessing.

4. **`app:deploy` verifies and can fail.** It prints the release identity, asserts that no
   migration is pending and that the columns this release's code reads are present, and
   returns non-zero **before** `artisan up` — so a release that did not land keeps serving
   the maintenance page rather than half of itself. It also resets OPcache where the SAPI
   shares one, and where it cannot (a separate FPM pool with
   `opcache.validate_timestamps=Off`) it says so, with the remedy, instead of leaving it
   silent.

## Consequences

- A stale or wrong-branch deploy is now a visible, named condition rather than a
  behaviour report that cannot be reproduced.
- `DeployCommand::REQUIRED_SCHEMA` and `ReleaseStatusService::FINGERPRINTED` are
  deliberately short — one entry per subsystem that has already been reported broken after
  a deploy. They are not schema mirrors or integrity checks and must not grow into either;
  `DeploymentIdentityTest` pins the deploy's list against the test's so the two cannot
  drift.
- The schema assertion catches a condition `migrate` reporting success does not: a
  migration that fails **part way** on MySQL leaves its earlier statements applied and is
  not recorded as run. That was observed on this codebase while reproducing the release,
  and it is why the check asserts columns rather than trusting the migration log.
- Two releases numbered v2.108.0 appear in the version history. They are kept as they
  shipped rather than renumbered after the fact; the dates distinguish them.
- **This does not make a report of "deployed and verified" trustworthy by itself.** It
  makes the claim checkable. The honest statement after a deploy is now: the console and
  the deploy log print the same signature, therefore the running code is the released
  code.
