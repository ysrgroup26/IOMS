# Local Verification Workflow

**Why this document exists.** For most of this project's history nothing was
ever verified in a browser — MySQL was unreachable in the development
environment, so every claim about the UI came from reading source code. A
single 30-minute browser session then found four defects that months of code
review had missed, including a wordmark showing the wrong product name on the
login screen and a header that overflowed horizontally on every phone width.

The lesson is not "we should have looked harder at the code". It is that some
classes of defect are **only** visible when the application is running. This
file makes that check reproducible so it stops being exceptional.

The intended loop is:

```
code → tests → build → browser → responsive check → report
```

Compilation is not verification. `npm run build` succeeding tells you the
JavaScript parsed, nothing more.

---

## 1. Run the test suite

```bash
vendor/bin/phpunit
```

The suite runs against an **in-memory SQLite** database (`phpunit.xml`), so it
needs no MySQL and no external services.

> Note: the schema only became provisionable on SQLite after six migrations
> that used MySQL-only raw SQL were made driver-aware. If you add a migration,
> avoid `ALTER TABLE ... MODIFY COLUMN`, multi-table `UPDATE ... JOIN`, and
> anything reading `information_schema`, or guard it with
> `DB::getDriverName() === 'mysql'`. Otherwise you silently break the ability
> to test the whole application.

## 2. Build front-end assets

```bash
npm run build
```

Production has no npm, so built assets are committed. Always rebuild before
verifying, or the browser will serve a stale bundle and you will "verify"
code you did not change.

## 3. Start the app

If MySQL is available, just run the app as usual. If it is **not** (the common
case in this project's dev environment), point the app at a SQLite database:

```bash
cp .env .env.backup                      # ALWAYS back up first

# in .env:
#   DB_CONNECTION=sqlite
#   DB_DATABASE=<absolute path>/preview.sqlite
#   SESSION_DRIVER=file                  # session storage is DB-backed by default

touch <absolute path>/preview.sqlite
php artisan migrate --force
php artisan config:clear
php artisan serve --port=8000
```

**Restore `.env` when finished** (`mv .env.backup .env`). `.env` is gitignored,
so this never reaches the repository — but leaving it pointed at SQLite will
confuse the next person.

## 4. Seed enough data to be realistic

Verifying a workforce selector against three employees proves nothing. The
defects found in practice — an unusable dropdown, duplicate-name collisions —
only appear at realistic scale:

```bash
php artisan tinker --execute='...'   # tenant + company + departments
                                     # + ~140 employees + an admin user
```

Seed **deliberate duplicate names**. Real industrial workforces are full of
them, and they exposed a genuine defect (two different employees rendering as
two identical chips).

## 5. Check for a stale Vite marker

If the app renders a **completely blank page** with `ERR_CONNECTION_REFUSED`
for every asset, check for `public/hot`:

```bash
rm -f public/hot
```

`npm run dev` creates this file and removes it on exit; a crashed dev server
leaves it behind, and every asset URL then points at a dev server that is not
running. It is now gitignored, but it can still exist locally.

## 6. Verify in the browser — not just that it renders

Log in and exercise the actual behaviour. At minimum, for anything touching UI:

- **Interaction**: does the feature do the thing (search, select, clear)?
- **Network**: is it calling what you expect, and how often? (Debouncing is
  invisible in source but obvious in the network panel.)
- **Console**: any errors?

## 7. Responsive check — measure, do not eyeball

Screenshots hide horizontal overflow. Measure it:

```js
({
  vw: document.documentElement.clientWidth,
  scrollW: document.documentElement.scrollWidth,
  overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
})
```

Run at **320, 375, 390 and 430** px. If `overflow` is true, find the culprit:

```js
Array.from(document.querySelectorAll('*'))
  .filter((el) => el.getBoundingClientRect().right > window.innerWidth + 1)
  .map((el) => ({ tag: el.tagName, cls: el.className.toString().slice(0, 60) }));
```

Isolate whether it is *your* content or the app shell by comparing
`document.querySelector('main').scrollWidth` against the header's — that
distinction is what proved a recent overflow was pre-existing rather than newly
introduced.

## 8. Restore the environment

- `mv .env.backup .env`
- stop the dev server

## What this cannot verify

Be honest in reports about the boundary. This workflow does **not** cover
MySQL-specific behaviour (notably `lockForUpdate` row/gap locking, which SQLite
serialises globally — a "concurrency test" there proves nothing), real
concurrency, production data volumes, or email/queue side effects.
